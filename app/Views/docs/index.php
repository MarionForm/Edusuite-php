<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Dispensas</title>
</head>
<body style="font-family:Arial; padding:20px; max-width:900px;">

  <h1>📄 Dispensas (Markdown → PDF)</h1>
  <p><a href="/">← Home</a></p>

  <h2>Crear nueva</h2>

  <form method="post" action="/docs/create"
        style="border:1px solid #ddd; padding:12px; border-radius:8px;">

    <div style="margin-bottom:8px;">
      <label>Título</label><br>
      <input name="title" style="width:100%; padding:8px;"
             placeholder="Ej: Seguridad básica - Phishing" required>
    </div>

    <div style="margin-bottom:8px;">
      <label>Versión</label><br>
      <select name="variant" style="padding:8px;">
        <option value="alumno">Alumno</option>
        <option value="docente">Docente</option>
      </select>
    </div>

    <div style="margin-bottom:8px;">
      <label>Markdown</label><br>
      <textarea name="markdown" rows="12"
        style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"
        placeholder="# Título
## Sección
- Punto 1
- Punto 2

[CODIGO]
ipconfig /all
[/CODIGO]" required></textarea>
    </div>

    <!-- BOTÓN (bien visible) -->
    <div style="position: sticky; bottom: 0; background: #fff; padding-top: 10px; border-top: 1px solid #eee;">
      <button type="submit"
        style="width:100%; padding:14px; font-size:18px; font-weight:bold; border:0; border-radius:10px; cursor:pointer;">
        ✅ Guardar dispensa
      </button>
    </div>

  </form>

  <h2 style="margin-top:20px;">Listado</h2>

  <?php if (empty($docs)): ?>
    <p>No hay dispensas todavía.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0"
           style="border-collapse:collapse; width:100%;">
      <tr>
        <th>ID</th><th>Título</th><th>Versión</th><th>Fecha</th><th>PDF</th>
      </tr>
      <?php foreach ($docs as $d): ?>
        <tr>
          <td><?= (int)$d['id'] ?></td>
          <td><?= htmlspecialchars($d['title']) ?></td>
          <td><?= htmlspecialchars($d['variant']) ?></td>
          <td><?= htmlspecialchars($d['created_at']) ?></td>
          <td><a href="/docs/export?id=<?= (int)$d['id'] ?>" target="_blank">Ver PDF</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

</body>
</html>
