<?php
// sidebar.php
?>
<div class="sidebar-title">Панель управления</div>
<nav>
    <a href="?action=upgrade" class="<?= ($_GET['action'] ?? '') == 'upgrade' ? 'active' : '' ?>">🚀 Улучшение кода</a>
    <a href="?action=import" class="<?= ($_GET['action'] ?? '') == 'import' ? 'active' : '' ?>">📥 Импорт информации</a>
    <a href="?action=create" class="<?= ($_GET['action'] ?? '') == 'create' ? 'active' : '' ?>">🏗️ Создание элементов</a>
</nav>
<div class="sidebar-footer">v 1.0.3</div>
