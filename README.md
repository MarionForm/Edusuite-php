# EduSuite PHP (Micro-LMS + Dispensas PDF)

Suite ligera en PHP + SQLite para docencia (FPE / academias):
- Dispensas: Markdown → PDF (con portada, índice, footer)
- Versión **Alumno** vs **Docente** (bloques condicionales)
- Cache y versionado automático de PDFs por hash
- SQLite (sin MySQL), fácil de desplegar

## Requisitos
- PHP 7.4+ (probado con 7.4.33)
- Composer
- Extensiones PHP: PDO + pdo_sqlite

## Instalación
```bash
composer install
php scripts/init_db.php
php scripts/seed_demo.php
php -S localhost:8080 -t public
