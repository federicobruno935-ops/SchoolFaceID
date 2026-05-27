<?php
// Gestione impostazioni persistenti (cache/impostazioni.json)

function get_impostazioni(): array {
    $file = __DIR__ . '/../cache/impostazioni.json';
    $default = [
        'ora_inizio_lezioni' => '08:00:00',
        'minuti_ritardo'     => 15,
    ];
    if (!file_exists($file)) return $default;
    $data = json_decode(@file_get_contents($file), true);
    return is_array($data) ? array_merge($default, $data) : $default;
}

function set_impostazioni(array $nuove): bool {
    $file = __DIR__ . '/../cache/impostazioni.json';
    $attuali = get_impostazioni();
    $merge = array_merge($attuali, $nuove);
    return (bool) @file_put_contents($file, json_encode($merge, JSON_PRETTY_PRINT));
}
