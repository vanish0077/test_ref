<?php
// sidebar.php
// Используем глобальную $action для подсветки
?>
<div style="padding: 25px 20px; font-weight: bold; font-size: 1.2em; letter-spacing: 1px; color: #fff; border-bottom: 1px solid #334155;">
    ASPRO CRM
</div>
<ul>
    <li>
        <a href="?action=home" class="<?php echo ($action === 'home') ? 'active' : ''; ?>">
            🏠 Главная
        </a>
    </li>
    <li>
        <a href="?action=upgrade" class="<?php echo ($action === 'upgrade') ? 'active' : ''; ?>">
            🚀 Улучшение кода
        </a>
    </li>
    <li>
        <a href="?action=import" class="<?php echo ($action === 'import') ? 'active' : ''; ?>">
            📥 Импорт контента
        </a>
    </li>
    <li>
        <a href="?action=create" class="<?php echo ($action === 'create') ? 'active' : ''; ?>">
            📄 Создание страниц
        </a>
    </li>
</ul>

<div style="position: absolute; bottom: 20px; left: 20px; font-size: 0.8em; color: #64748b;">
    v 1.2.5
</div>
