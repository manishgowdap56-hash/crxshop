<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = db();
$method = request_method();

if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT c.id, c.name, c.sort_order, c.enabled, c.created_at,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
         FROM categories c ORDER BY c.sort_order, c.name'
    )->fetchAll();
    json_ok(['categories' => array_map(function ($r) {
        return [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'sort_order'    => (int)$r['sort_order'],
            'enabled'       => (int)$r['enabled'] === 1,
            'product_count' => (int)$r['product_count'],
            'created_at'    => $r['created_at'],
        ];
    }, $rows)]);
}

csrf_verify();

$action = input_str('action');

if ($action === 'create') {
    $name = trim((string)input_param('name', ''));
    if ($name === '') json_error('Category name is required.', 422);

    $dup = $pdo->prepare('SELECT id FROM categories WHERE name = :n LIMIT 1');
    $dup->execute([':n' => $name]);
    if ($dup->fetch()) json_error('Category already exists.', 409);

    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $slug = trim($slug, '-');

    $posIn = input_param('sort_order', null);
    if ($posIn !== null && $posIn !== '') {
        if (!is_scalar($posIn) || !preg_match('/^-?\d{1,6}$/', trim((string)$posIn))) json_error('Position must be a whole number.', 422);
        $pos = (int)trim((string)$posIn);
    } else {
        $pos = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories')->fetchColumn();
    }
    $pdo->prepare('INSERT INTO categories (name, slug, sort_order, enabled, created_at) VALUES (:n, :s, :o, 1, NOW())')
        ->execute([':n' => $name, ':s' => $slug, ':o' => $pos]);

    json_ok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update') {
    $id = input_int('id');
    $name = trim((string)input_param('name', ''));
    $posIn = input_param('sort_order', null);
    if ($id <= 0) json_error('Invalid input.', 422);

    if ($posIn !== null && $posIn !== '') {
        // Position only (name omitted) or together with a rename.
        if (!is_scalar($posIn) || !preg_match('/^-?\d{1,6}$/', trim((string)$posIn))) json_error('Position must be a whole number.', 422);
        $pdo->prepare('UPDATE categories SET sort_order = :o WHERE id = :id')->execute([':o' => (int)trim((string)$posIn), ':id' => $id]);
        if ($name === '') json_ok();
    }
    if ($name === '') json_error('Invalid input.', 422);

    $dup = $pdo->prepare('SELECT id FROM categories WHERE name = :n AND id <> :id LIMIT 1');
    $dup->execute([':n' => $name, ':id' => $id]);
    if ($dup->fetch()) json_error('Another category uses that name.', 409);

    $pdo->prepare('UPDATE categories SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $id]);
    json_ok();
}

if ($action === 'delete') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid id.', 422);

    $has = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
    $has->execute([':id' => $id]);
    if ((int)$has->fetchColumn() > 0) json_error('Category has products. Delete or move them first.', 409);

    $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => $id]);
    json_ok();
}

json_error('Unknown action.', 400);