<?php
// FPP sets $plugin (the installed plugin folder, i.e. repoName) and $menu
// before including this file - see fpp-plugin-Template's menu.inc. Don't
// derive $plugin from __FILE__: this file is nested inside the repo.
$menuEntries = [
    [
        'text' => 'Animatronic Capture',
        'type' => 'content',
        'page' => 'content.php',
        'wrap' => 1,
    ],
];
foreach ($menuEntries as $entry) {
    if ($entry['type'] !== $menu) continue;
    printf("<li><a href='plugin.php?plugin=%s&page=%s'>%s</a></li>\n",
        $plugin, $entry['page'], $entry['text']);
}
?>
