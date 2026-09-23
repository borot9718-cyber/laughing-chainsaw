<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Boutique communautaire : tout membre peut vendre, ajouter aux favoris,
 * remplir un panier et passer commande. Le paiement n'est PAS géré en ligne :
 * la commande est transmise au vendeur, qui la confirme et convient du
 * paiement/de la livraison avec l'acheteur (architecture extensible).
 */
final class Shop extends Module
{
    public const CATEGORIES = ['Mode', 'Électronique', 'Maison', 'Beauté', 'Alimentation', 'Livres', 'Sport', 'Services', 'Autre'];

    /** Transitions autorisées : statut actuel => [statut cible => rôles autorisés]. */
    private const TRANSITIONS = [
        'pending'   => ['confirmed' => ['seller'], 'cancelled' => ['seller', 'buyer']],
        'confirmed' => ['shipped' => ['seller'], 'cancelled' => ['seller']],
        'shipped'   => ['delivered' => ['seller']],
    ];

    private const STATUS_LABELS = [
        'pending' => 'en attente', 'confirmed' => 'confirmée', 'shipped' => 'expédiée',
        'delivered' => 'livrée', 'cancelled' => 'annulée',
    ];

    public function handle(string $path, string $method, array $input): void
    {
        if (!str_starts_with($path, 'store/')) return;

        if ($path === 'store/meta' && $method === 'GET') {
            $this->ok(['categories' => self::CATEGORIES, 'currency' => $this->currency()]);
        }
        if ($path === 'store/products' && $method === 'GET')  $this->listProducts();
        if ($path === 'store/products' && $method === 'POST') $this->saveProduct(null, $input);

        if (preg_match('#^store/products/([a-zA-Z0-9_]+)(?:/([a-z]+))?$#', $path, $m)) {
            $id  = $m[1];
            $sub = $m[2] ?? '';
            if ($sub === ''         && $method === 'DELETE') $this->deleteProduct($id);
            if ($sub === 'update'   && $method === 'POST')   $this->saveProduct($id, $input);
            if ($sub === 'favorite' && $method === 'POST')   $this->toggleFavorite($id);
        }

        if ($path === 'store/cart' && $method === 'GET')       $this->cart();
        if ($path === 'store/cart/set' && $method === 'POST')  $this->cartSet($input);
        if ($path === 'store/checkout' && $method === 'POST')  $this->checkout($input);
        if ($path === 'store/orders' && $method === 'GET')     $this->orders();
        if (preg_match('#^store/orders/([a-zA-Z0-9_]+)/status$#', $path, $m) && $method === 'POST') {
            $this->orderStatus($m[1], $input);
        }
    }

    private function currency(): string
    {
        return (string)$this->env->get('STORE_CURRENCY', 'FCFA');
    }

    // ── Produits ───────────────────────────────────────────────────────────
    private function presentProduct(array $p, array $users, array $favs): array
    {
        $s = $users[$p['seller_id'] ?? ''] ?? null;
        return [
            'id'          => $p['id'],
            'title'       => (string)($p['title'] ?? ''),
            'description' => (string)($p['description'] ?? ''),
            'price'       => (int)($p['price'] ?? 0),
            'currency'    => $this->currency(),
            'category'    => (string)($p['category'] ?? 'Autre'),
            'stock'       => (int)($p['stock'] ?? 0),
            'image_url'   => (string)($p['image_url'] ?? ''),
            'seller'      => $this->pub($s),
            'created_at'  => $p['created_at'] ?? null,
            'is_favorite' => isset($favs[$p['id']]),
        ];
    }

    private function favoriteIds(string $uid): array
    {
        $favs = [];
        foreach ($this->store->all('favorites') as $f) {
            if (($f['user_id'] ?? '') === $uid && ($f['type'] ?? 'product') === 'product') $favs[(string)($f['target_id'] ?? '')] = true;
        }
        return $favs;
    }

    private function listProducts(): never
    {
        $u     = $this->me();
        $q     = $this->text($_GET['q'] ?? '', 80);
        $cat   = $this->text($_GET['category'] ?? '', 40);
        $mine  = !empty($_GET['mine']);
        $onlyF = !empty($_GET['favorites']);
        $sort  = (string)($_GET['sort'] ?? 'recent');
        $users = $this->usersMap();
        $favs  = $this->favoriteIds($u['id']);
        $blocked = array_flip($this->blockedIdsFor($u['id']));

        $out = [];
        foreach ($this->store->all('products') as $p) {
            if (!empty($p['deleted'])) continue;
            $sid = (string)($p['seller_id'] ?? '');
            if ($mine && $sid !== $u['id']) continue;
            if (!$mine && isset($blocked[$sid])) continue;
            if ($onlyF && !isset($favs[$p['id']])) continue;
            if ($cat !== '' && ($p['category'] ?? '') !== $cat) continue;
            if ($q !== '' && !$this->contains((string)($p['title'] ?? '') . ' ' . (string)($p['description'] ?? ''), $q)) continue;
            $out[] = $this->presentProduct($p, $users, $favs);
        }
        if ($sort === 'price_asc')      usort($out, fn($a, $b) => $a['price'] <=> $b['price']);
        elseif ($sort === 'price_desc') usort($out, fn($a, $b) => $b['price'] <=> $a['price']);
        else                            usort($out, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        $this->ok(['products' => $out]);
    }

    private function saveProduct(?string $id, array $input): never
    {
        $u = $this->me();
        $existing = null;
        if ($id !== null) {
            $existing = $this->findById('products', $id);
            if (!$existing || !empty($existing['deleted'])) $this->fail('Produit introuvable.', 404);
            if (($existing['seller_id'] ?? '') !== $u['id']) $this->fail('Vous ne pouvez modifier que vos propres produits.', 403);
        }

        $title = $this->text($input['title'] ?? '', 120);
        if (mb_strlen($title) < 2) $this->fail('Le titre doit contenir au moins 2 caractères.');
        $price = (int)round((float)str_replace([' ', ','], ['', '.'], (string)($input['price'] ?? '0')));
        if ($price < 0 || $price > 1000000000) $this->fail('Prix invalide.');
        $stock = (int)($input['stock'] ?? 1);
        if ($stock < 0 || $stock > 100000) $this->fail('Stock invalide.');
        $cat = (string)($input['category'] ?? 'Autre');
        if (!in_array($cat, self::CATEGORIES, true)) $cat = 'Autre';
        $desc = $this->text($input['description'] ?? '', 2000);
        $img  = $this->uploadOptional('image', 'products', 8 * 1048576, false);

        if ($existing) {
            $changes = ['title' => $title, 'description' => $desc, 'price' => $price, 'stock' => $stock, 'category' => $cat, 'updated_at' => date('c')];
            if ($img !== null) {
                $changes['image_url'] = $img;
                $oldImage = (string)($existing['image_url'] ?? '');
                if ($oldImage !== '' && $oldImage !== $img) $this->deleteImage($oldImage);
            }
            $this->store->updateWhere('products', fn($p) => ($p['id'] ?? '') === $id, fn($p) => array_merge($p, $changes));
            $this->ok(['message' => 'Produit mis à jour.']);
        }
        $product = [
            'id' => $this->newId('prd_'), 'seller_id' => $u['id'], 'title' => $title, 'description' => $desc,
            'price' => $price, 'stock' => $stock, 'category' => $cat, 'image_url' => $img ?? '',
            'status' => 'active', 'created_at' => date('c'), 'deleted' => false,
        ];
        $this->store->insert('products', $product);
        $this->ok(['product' => $this->presentProduct($product, [$u['id'] => $u], [])], 201);
    }

    private function deleteProduct(string $id): never
    {
        $u = $this->me();
        $count = $this->store->updateWhere('products',
            fn($p) => ($p['id'] ?? '') === $id && ($p['seller_id'] ?? '') === $u['id'] && empty($p['deleted']),
            fn($p) => array_merge($p, ['deleted' => true, 'deleted_at' => date('c')])
        );
        if (!$count) $this->fail('Produit introuvable.', 404);
        $this->ok();
    }

    private function toggleFavorite(string $id): never
    {
        $u = $this->me();
        $p = $this->findById('products', $id);
        if (!$p || !empty($p['deleted'])) $this->fail('Produit introuvable.', 404);
        $rows = $this->store->all('favorites');
        $found = false;
        $kept = [];
        foreach ($rows as $f) {
            if (($f['user_id'] ?? '') === $u['id'] && ($f['target_id'] ?? '') === $id && ($f['type'] ?? 'product') === 'product') { $found = true; continue; }
            $kept[] = $f;
        }
        if (!$found) $kept[] = ['id' => $this->newId('fav_'), 'user_id' => $u['id'], 'type' => 'product', 'target_id' => $id, 'created_at' => date('c')];
        $this->store->replace('favorites', $kept);
        $this->ok(['is_favorite' => !$found]);
    }

    // ── Panier ─────────────────────────────────────────────────────────────
    private function cartItems(string $uid): array
    {
        foreach ($this->store->all('carts') as $c) {
            if (($c['user_id'] ?? '') === $uid) return is_array($c['items'] ?? null) ? $c['items'] : [];
        }
        return [];
    }

    private function saveCart(string $uid, array $items): void
    {
        $rows = array_values(array_filter($this->store->all('carts'), fn($c) => ($c['user_id'] ?? '') !== $uid));
        if ($items) $rows[] = ['user_id' => $uid, 'items' => array_values($items), 'updated_at' => date('c')];
        $this->store->replace('carts', $rows);
    }

    private function cart(): never
    {
        $u     = $this->me();
        $users = $this->usersMap();
        $lines = [];
        $total = 0;
        foreach ($this->cartItems($u['id']) as $it) {
            $p = $this->findById('products', (string)($it['product_id'] ?? ''));
            if (!$p || !empty($p['deleted'])) continue;
            $qty = max(1, min((int)($it['qty'] ?? 1), max(1, (int)($p['stock'] ?? 0))));
            $line = (int)$p['price'] * $qty;
            $total += $line;
            $lines[] = [
                'product' => $this->presentProduct($p, $users, []),
                'qty'     => $qty,
                'line_total' => $line,
                'available'  => (int)($p['stock'] ?? 0) > 0,
            ];
        }
        $this->ok(['items' => $lines, 'total' => $total, 'currency' => $this->currency()]);
    }

    private function cartSet(array $input): never
    {
        $u   = $this->me();
        $pid = (string)($input['product_id'] ?? '');
        $qty = (int)($input['qty'] ?? 1);
        $items = [];
        foreach ($this->cartItems($u['id']) as $it) {
            if (($it['product_id'] ?? '') !== $pid) $items[] = $it;
        }
        if ($qty > 0) {
            $p = $this->findById('products', $pid);
            if (!$p || !empty($p['deleted'])) $this->fail('Produit introuvable.', 404);
            if (($p['seller_id'] ?? '') === $u['id']) $this->fail('Vous ne pouvez pas acheter votre propre produit.');
            if ((int)($p['stock'] ?? 0) < 1) $this->fail('Ce produit est en rupture de stock.');
            $qty = min($qty, (int)$p['stock']);
            $items[] = ['product_id' => $pid, 'qty' => $qty];
        }
        $this->saveCart($u['id'], $items);
        $this->ok(['count' => array_sum(array_map(fn($i) => (int)$i['qty'], $items))]);
    }

    // ── Commandes ──────────────────────────────────────────────────────────
    private function checkout(array $input): never
    {
        $u = $this->me();
        $phone   = $this->text($input['phone'] ?? '', 30);
        $address = $this->text($input['address'] ?? '', 300);
        $note    = $this->text($input['note'] ?? '', 500);
        if ($phone === '' || $address === '') $this->fail('Téléphone et adresse de livraison sont obligatoires.');

        $items = $this->cartItems($u['id']);
        if (!$items) $this->fail('Votre panier est vide.');

        // Regroupe par vendeur et vérifie le stock avant toute écriture.
        $bySeller = [];
        $products = $this->store->all('products');
        $index = [];
        foreach ($products as $i => $p) $index[$p['id']] = $i;
        foreach ($items as $it) {
            $pid = (string)($it['product_id'] ?? '');
            if (!isset($index[$pid])) $this->fail('Un produit de votre panier n’existe plus.');
            $p = $products[$index[$pid]];
            if (!empty($p['deleted'])) $this->fail('« ' . ($p['title'] ?? 'Un produit') . ' » n’est plus disponible.');
            if (($p['seller_id'] ?? '') === $u['id']) $this->fail('Vous ne pouvez pas acheter votre propre produit.');
            $qty = (int)($it['qty'] ?? 1);
            if ($qty < 1 || $qty > (int)($p['stock'] ?? 0)) $this->fail('Stock insuffisant pour « ' . ($p['title'] ?? 'un produit') . ' ».');
            $bySeller[(string)$p['seller_id']][] = ['p' => $p, 'qty' => $qty];
        }

        $created = [];
        foreach ($bySeller as $sellerId => $lines) {
            $orderItems = [];
            $total = 0;
            foreach ($lines as $l) {
                $orderItems[] = [
                    'product_id' => $l['p']['id'], 'title' => $l['p']['title'], 'price' => (int)$l['p']['price'],
                    'qty' => $l['qty'], 'image_url' => (string)($l['p']['image_url'] ?? ''),
                ];
                $total += (int)$l['p']['price'] * $l['qty'];
                $products[$index[$l['p']['id']]]['stock'] = (int)$products[$index[$l['p']['id']]]['stock'] - $l['qty'];
            }
            $order = [
                'id' => $this->newId('ord_'), 'buyer_id' => $u['id'], 'seller_id' => $sellerId,
                'items' => $orderItems, 'total' => $total, 'currency' => $this->currency(),
                'status' => 'pending', 'payment_method' => 'offline',
                'phone' => $phone, 'address' => $address, 'note' => $note,
                'created_at' => date('c'), 'updated_at' => date('c'),
            ];
            $created[] = $order;
        }
        $this->store->replace('products', $products);
        foreach ($created as $order) {
            $this->store->insert('orders', $order);
            $this->notify($order['seller_id'], 'order_new',
                ($u['display_name'] ?: 'Un acheteur') . ' vous a passé une commande de ' . number_format((int)$order['total'], 0, ',', ' ') . ' ' . $order['currency'] . '.',
                ['link' => '/boutique?tab=sales', 'order_id' => $order['id']]);
        }
        $this->saveCart($u['id'], []);
        $this->ok(['orders' => array_map(fn($o) => $o['id'], $created), 'message' => 'Commande envoyée au vendeur.'], 201);
    }

    private function orders(): never
    {
        $u     = $this->me();
        $role  = (string)($_GET['role'] ?? 'buyer') === 'seller' ? 'seller' : 'buyer';
        $users = $this->usersMap();
        $key   = $role === 'seller' ? 'seller_id' : 'buyer_id';
        $out   = [];
        foreach ($this->store->all('orders') as $o) {
            if (($o[$key] ?? '') !== $u['id']) continue;
            $o['buyer']  = $this->pub($users[$o['buyer_id'] ?? ''] ?? null);
            $o['seller'] = $this->pub($users[$o['seller_id'] ?? ''] ?? null);
            $o['next'] = $this->nextStatuses((string)($o['status'] ?? 'pending'), $role);
            $out[] = $o;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $this->ok(['orders' => $out, 'role' => $role]);
    }

    private function nextStatuses(string $status, string $role): array
    {
        $out = [];
        foreach (self::TRANSITIONS[$status] ?? [] as $target => $roles) {
            if (in_array($role, $roles, true)) $out[] = $target;
        }
        return $out;
    }

    private function orderStatus(string $id, array $input): never
    {
        $u = $this->me();
        $order = $this->findById('orders', $id);
        if (!$order) $this->fail('Commande introuvable.', 404);
        $role = null;
        if (($order['seller_id'] ?? '') === $u['id']) $role = 'seller';
        elseif (($order['buyer_id'] ?? '') === $u['id']) $role = 'buyer';
        if (!$role) $this->fail('Commande introuvable.', 404);

        $target = (string)($input['status'] ?? '');
        if (!in_array($target, $this->nextStatuses((string)($order['status'] ?? 'pending'), $role), true)) {
            $this->fail('Ce changement de statut n’est pas autorisé.');
        }
        $this->store->updateWhere('orders', fn($o) => ($o['id'] ?? '') === $id,
            fn($o) => array_merge($o, ['status' => $target, 'updated_at' => date('c')]));

        // Annulation : le stock est remis en vente.
        if ($target === 'cancelled') {
            $qtyBy = [];
            foreach ((array)($order['items'] ?? []) as $it) $qtyBy[(string)$it['product_id']] = (int)$it['qty'];
            $this->store->updateWhere('products', fn($p) => isset($qtyBy[$p['id'] ?? '']),
                fn($p) => array_merge($p, ['stock' => (int)($p['stock'] ?? 0) + $qtyBy[$p['id']]]));
        }
        $notifyId = $role === 'seller' ? (string)$order['buyer_id'] : (string)$order['seller_id'];
        $this->notify($notifyId, 'order_status', 'Une commande est maintenant ' . (self::STATUS_LABELS[$target] ?? $target) . '.',
            ['link' => '/boutique?tab=' . ($role === 'seller' ? 'orders' : 'sales'), 'order_id' => $id]);
        $this->ok(['status' => $target]);
    }
}
