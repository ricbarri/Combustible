<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/auth.php';
logout(); // Limpia sesión local y redirige al logout de Microsoft
