<?php
namespace App\Controllers;

use App\Core\DB;
use App\Core\View;
use Dompdf\Dompdf;

final class DocsController {

  public function index(): void {
    $docs = DB::pdo()
      ->query("SELECT id,title,variant,created_at FROM markdown_docs ORDER BY id DESC")
      ->fetchAll();

    View::render('docs/index', ['docs' => $docs]);
  }

  public function create(): void {
    $title   = trim($_POST['title'] ?? '');
    $variant = $_POST['variant'] ?? 'alumno';
    $md      = $_POST['markdown'] ?? '';

    if ($title === '' || $md === '') {
      http_response_code(400);
      echo "Faltan datos (título/markdown)";
      return;
    }

    // Guardamos MARKDOWN (texto). El HTML/PDF se genera al exportar.
    $stmt = DB::pdo()->prepare(
      "INSERT INTO markdown_docs(title, markdown, variant, created_by) VALUES(?,?,?,?)"
    );

    // created_by=1 (usuario demo). Luego lo sustituimos por el usuario autenticado.
    $stmt->execute([$title, $md, $variant, 1]);

    header("Location: /docs");
  }

  public function exportPdf(): void {
    $id = (int)($_GET['id'] ?? 0);

    $stmt = DB::pdo()->prepare(
      "SELECT id, title, markdown, variant, created_at FROM markdown_docs WHERE id=?"
    );
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc) { http_response_code(404); echo "No existe"; return; }

    $variant = (string)$doc['variant'];

    // 1) Filtrar Markdown según variante (alumno/docente)
    $filteredMd = $this->filterByVariant((string)$doc['markdown'], $variant);

    // 2) Hash para versioning automático (cambia si cambia el contenido)
    $hash8 = substr(sha1($filteredMd), 0, 8);

    // 3) Ruta cache PDF
    $exportsDir = realpath(__DIR__ . '/../../storage/exports');
    if ($exportsDir === false) {
      $exportsDir = __DIR__ . '/../../storage/exports';
      @mkdir($exportsDir, 0775, true);
      $exportsDir = realpath($exportsDir) ?: $exportsDir;
    }

    $safeVariant = preg_replace('/[^a-z0-9_\-]/i', '', $variant);
    $pdfFileName = "dispensa_{$doc['id']}_{$safeVariant}_{$hash8}.pdf";
    $pdfPath = rtrim($exportsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pdfFileName;

    // 4) Si ya existe, servir directamente
    if (file_exists($pdfPath) && filesize($pdfPath) > 0) {
      $this->registerExportIfNeeded(
        'dispensa_pdf',
        'markdown_docs',
        (int)$doc['id'],
        'storage/exports/' . $pdfFileName,
        1 // created_by demo
      );
      $this->sendPdf($pdfPath, $pdfFileName);
      return;
    }

    // 5) Generar contenido (TOC + HTML) sobre Markdown filtrado
    $toc      = $this->buildToc($filteredMd);   // índice
    $htmlBody = $this->mdToHtml($filteredMd);   // contenido

    // 6) HTML final (portada + índice + contenido + footer)
    $docenteName = 'Docente Demo'; // luego: usuario autenticado
    $html = '
      <style>
        body{ font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1,h2,h3{ margin: 0 0 10px 0; }
        .box{ border:1px solid #ddd; padding:12px; border-radius:6px; }

        .cover{
          page-break-after: always;
          border: 2px solid #111;
          padding: 24px;
          border-radius: 14px;
        }
        .cover h1{ font-size: 26px; margin-bottom: 8px; }
        .cover .sub{ font-size: 13px; color:#555; margin-bottom: 16px; }
        .pill{
          display:inline-block; padding:6px 10px; border:1px solid #ddd; border-radius:999px;
          font-size:11px; margin-right:6px; color:#333;
        }

        .toc{ page-break-after: always; }
        .toc h2{ margin-top:0; }
        .toc ul{ padding-left:18px; }
        .toc li{ margin-bottom:6px; }

        code{ background:#f4f4f4; padding:2px 4px; border-radius:4px; }
        pre{ background:#f4f4f4; padding:10px; border-radius:6px; }

        @page { margin: 18mm 14mm; }
        .footer {
          position: fixed;
          bottom: -10mm;
          left: 0;
          right: 0;
          text-align: center;
          font-size: 10px;
          color: #888;
        }
      </style>

      <div class="footer">EduSuite PHP · Página {PAGE_NUM} / {PAGE_COUNT}</div>

      <!-- PORTADA -->
      <div class="cover">
        <h1>'. $this->esc((string)$doc['title']) .'</h1>
        <div class="sub">Dispensa generada automáticamente (Markdown → PDF)</div>
        <div style="margin-bottom:14px;">
          <span class="pill">Versión: '. $this->esc($variant) .'</span>
          <span class="pill">Fecha: '. $this->esc((string)$doc['created_at']) .'</span>
          <span class="pill">Docente: '. $this->esc($docenteName) .'</span>
          <span class="pill">Hash: '. $this->esc($hash8) .'</span>
        </div>
        <div style="margin-top:18px; color:#444;">
          <strong>Uso:</strong> material didáctico para cursos FPE / academias.
          <br><strong>Nota:</strong> este PDF queda cacheado y versionado automáticamente.
        </div>
      </div>

      <!-- ÍNDICE -->
      <div class="toc">
        <h2>Índice</h2>
        '. $toc .'
      </div>

      <!-- CONTENIDO -->
      <div class="box">'. $htmlBody .'</div>
    ';

    // 7) Render PDF con dompdf
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfBinary = $dompdf->output();

    // 8) Guardar en disco
    file_put_contents($pdfPath, $pdfBinary);

    // 9) Registrar en tabla exports (si no existe ya)
    $this->registerExportIfNeeded(
      'dispensa_pdf',
      'markdown_docs',
      (int)$doc['id'],
      'storage/exports/' . $pdfFileName,
      1 // created_by demo
    );

    // 10) Servir PDF
    $this->sendPdf($pdfPath, $pdfFileName);
  }

  /**
   * (1) FILTRO por variante:
   * - alumno: oculta bloques :::solucion ... ::: y :::docente ... :::
   * - docente: muestra, transformando a secciones "Solución" y "Nota docente"
   *
   * Sintaxis en Markdown:
   * :::solucion
   * texto...
   * :::
   *
   * :::docente
   * texto...
   * :::
   */
  private function filterByVariant(string $md, string $variant): string {
    $pattern = '/:::(solucion|docente)\s*\R(.*?)\R:::/s';

    return preg_replace_callback($pattern, function($m) use ($variant) {
      $blockType = strtolower(trim($m[1]));
      $content   = trim($m[2]);

      if ($variant === 'alumno') {
        // Alumno: ocultamos todo lo "solución" y "docente"
        return '';
      }

      // Docente: mostramos y etiquetamos
      if ($blockType === 'solucion') {
        return "\n## ✅ Solución\n" . $content . "\n";
      }
      if ($blockType === 'docente') {
        return "\n## 💡 Nota docente\n" . $content . "\n";
      }

      return $content;
    }, $md);
  }

  private function sendPdf(string $absPath, string $downloadName): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.$downloadName.'"');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
  }

  private function registerExportIfNeeded(string $kind, string $refTable, int $refId, string $filePath, int $createdBy): void {
    $pdo = DB::pdo();

    $check = $pdo->prepare(
      "SELECT id FROM exports WHERE kind=? AND ref_table=? AND ref_id=? AND file_path=? LIMIT 1"
    );
    $check->execute([$kind, $refTable, $refId, $filePath]);
    $exists = $check->fetchColumn();

    if ($exists) return;

    $ins = $pdo->prepare(
      "INSERT INTO exports(kind, ref_table, ref_id, file_path, created_by) VALUES(?,?,?,?,?)"
    );
    $ins->execute([$kind, $refTable, $refId, $filePath, $createdBy]);
  }

  // Construye un índice (TOC) simple basado en #, ##, ###
  private function buildToc(string $md): string {
    $lines = preg_split("/\r\n|\n|\r/", $md);
    $items = [];

    foreach ($lines as $line) {
      $raw = rtrim($line);

      if (preg_match('/^(#{1,3})\s+(.*)$/', $raw, $m)) {
        $level = strlen($m[1]); // 1..3
        $text  = trim($m[2]);
        if ($text === '') continue;

        $items[] = ['level' => $level, 'text' => $text];
      }
    }

    if (empty($items)) {
      return '<p style="color:#666;">(No hay títulos detectados en el Markdown)</p>';
    }

    $html = '<ul>';
    foreach ($items as $it) {
      $indent = ($it['level'] - 1) * 18;
      $html .= '<li style="margin-left:'.$indent.'px;">'.$this->esc($it['text']).'</li>';
    }
    $html .= '</ul>';

    return $html;
  }

  // Markdown ultra simple (MVP) -> HTML
  private function mdToHtml(string $md): string {
    $lines = preg_split("/\r\n|\n|\r/", $md);
    $out = '';
    $inUl = false;
    $inCode = false;

    foreach ($lines as $line) {
      $raw = rtrim($line);

      // bloque de código ```
      if (trim($raw) === '```') {
        if (!$inCode) { $inCode = true; $out .= '<pre><code>'; }
        else { $inCode = false; $out .= '</code></pre>'; }
        continue;
      }
      if ($inCode) {
        $out .= $this->esc($raw) . "\n";
        continue;
      }

      // Soporte para [CODIGO] ... [/CODIGO]
      if (trim($raw) === '[CODIGO]') { $inCode = true; $out .= '<pre><code>'; continue; }
      if (trim($raw) === '[/CODIGO]') { $inCode = false; $out .= '</code></pre>'; continue; }

      if (preg_match('/^###\s+(.*)$/', $raw, $m)) { $this->closeUl($out, $inUl); $out .= "<h3>".$this->esc($m[1])."</h3>"; continue; }
      if (preg_match('/^##\s+(.*)$/',  $raw, $m)) { $this->closeUl($out, $inUl); $out .= "<h2>".$this->esc($m[1])."</h2>"; continue; }
      if (preg_match('/^#\s+(.*)$/',   $raw, $m)) { $this->closeUl($out, $inUl); $out .= "<h1>".$this->esc($m[1])."</h1>"; continue; }

      if (preg_match('/^\-\s+(.*)$/', $raw, $m)) {
        if (!$inUl) { $inUl = true; $out .= "<ul>"; }
        $out .= "<li>".$this->esc($m[1])."</li>";
        continue;
      } else {
        $this->closeUl($out, $inUl);
      }

      if (trim($raw) === '') { $out .= "<br>"; continue; }

      // inline code `...`
      $safe = $this->esc($raw);
      $safe = preg_replace('/`([^`]+)`/', '<code>$1</code>', $safe);
      $out .= "<p>{$safe}</p>";
    }

    $this->closeUl($out, $inUl);
    if ($inCode) $out .= "</code></pre>";
    return $out;
  }

  private function closeUl(string &$out, bool &$inUl): void {
    if ($inUl) { $out .= "</ul>"; $inUl = false; }
  }

  private function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
