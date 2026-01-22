<?php
// sidebar.php
?>
<div style="padding: 30px 20px; font-weight: 800; font-size: 1.3em; letter-spacing: 2px; color: #fff; text-align: center; background: rgba(0,0,0,0.2);">
    ASPRO.PRO
</div>

<ul>
    <li>
        <a href="?action=home" class="<?php echo ($action === 'home' || $action === '') ? 'active' : ''; ?>">
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

<div style="position: absolute; bottom: 20px; width: 100%; text-align: center; font-size: 11px; color: #475569; letter-spacing: 1px;">
    SYSTEM v1.2.5
</div>
