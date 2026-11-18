<?php
/** Application constants — module catalogue and ID format. */
declare(strict_types=1);

/** Admin-assigned nav modules. Admin always holds every key. */
const MODULES = [
    'cadets' => [
        'label' => 'Cadets',
        'icon'  => 'bi-people',
        'pages' => ['cadets/list.php', 'cadets/create.php', 'cadets/import.php', 'cadets/view.php'],
    ],
    'records' => [
        'label' => 'Records',
        'icon'  => 'bi-clipboard-data',
        'pages' => ['operations/promotions.php', 'operations/fitness.php', 'operations/attendance.php', 'operations/discipline.php'],
    ],
    'operations' => [
        'label' => 'Operations',
        'icon'  => 'bi-box-seam',
        'pages' => ['logistics/inventory.php', 'operations/duties.php'],
    ],
];

function module_keys(): array
{
    return array_keys(MODULES);
}

function module_label(string $key): string
{
    return MODULES[$key]['label'] ?? $key;
}

function service_no_prefix(): string
{
    return 'TaTUAFCC';
}
