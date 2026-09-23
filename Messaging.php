<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Messagerie privée 1-à-1 : conversations, messages texte + image jointe,
 * accusés de lecture, compteur de non-lus, blocage respecté.
 */
final class Messaging extends Module
{
    public function handle(string $path, string $method, array $input): void
    {
        if ($path === 'messages/unread-count' && $method === 'GET') $this->unreadTotal();
        if ($path === 'conversations' && $method === 'GET')  $this->listConversations();
        if ($path === 'conversations' && $method === 'POST') $this->openConversation($input);

        if (preg_match('#^conversations/([a-zA-Z0-9_]+)/messages$#', $path, $m)) {
            if ($method === 'GET')  $this->thread($m[1]);
            if ($method === 'POST') $this->send($m[1], $input);
        }
        if (preg_match('#^conversations/([a-zA-Z0-9_]+)/read$#', $path, $m) && $method === 'POST') {
            $this->markRead($m[1]);
        }
        if (preg_match('#^messages/([a-zA-Z0-9_]+)$#', $path, $m) && $method === 'DELETE') {
            $this->deleteMessage($m[1]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    /** Conversation dont $uid est participant, sinon 404. */
    private function conversationFor(string $convId, string $uid): array
    {
        $c = $this->findById('conversations', $convId);
        if (!$c || !in_array($uid, (array)($c['participants'] ?? []), true)) {
            $this->fail('Conversation introuvable.', 404);
        }
        return $c;
    }

    private function otherId(array $conv, string $uid): string
    {
        foreach ((array)($conv['participants'] ?? []) as $p) {
            if ($p !== $uid) return (string)$p;
        }
        return '';
    }

    /** @return array<string,string> conversation_id => dernier instant de lecture pour $uid */
    private function readsFor(string $uid): array
    {
        $out = [];
        foreach ($this->store->all('message_reads') as $r) {
            if (($r['user_id'] ?? '') === $uid) $out[(string)($r['conversation_id'] ?? '')] = (string)($r['last_read_at'] ?? '');
        }
        return $out;
    }

    private function unreadCounts(string $uid): array
    {
        $reads = $this->readsFor($uid);
        $mine  = [];
        foreach ($this->store->all('conversations') as $c) {
            if (in_array($uid, (array)($c['participants'] ?? []), true)) $mine[$c['id']] = true;
        }
        $counts = [];
        foreach ($this->store->all('messages') as $msg) {
            $cid = (string)($msg['conversation_id'] ?? '');
            if (!isset($mine[$cid]) || !empty($msg['deleted']) || ($msg['sender_id'] ?? '') === $uid) continue;
            $seen = $reads[$cid] ?? '';
            if ($seen === '' || strcmp((string)($msg['created_at'] ?? ''), $seen) > 0) {
                $counts[$cid] = ($counts[$cid] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Réglage « Qui peut m'écrire » du destinataire : tout le monde, uniquement les personnes qu'il
     * suit, ou personne. Une personne à qui il a déjà écrit peut toujours lui répondre.
     */
    private function mayMessage(string $senderId, array $recipient, string $convId = ''): bool
    {
        $perm = (string)($recipient['message_permission'] ?? 'everyone');
        if ($perm === 'everyone') return true;
        $rid = (string)$recipient['id'];
        foreach ($this->store->all('messages') as $m) {                     // il lui a déjà écrit : réponse autorisée
            if ($convId !== '' && ($m['conversation_id'] ?? '') === $convId && ($m['sender_id'] ?? '') === $rid) return true;
        }
        if ($perm === 'followers') {
            foreach ($this->store->all('followers') as $f) {
                if (($f['follower_id'] ?? '') === $rid && ($f['followed_id'] ?? '') === $senderId) return true;
            }
        }
        return false;
    }

    // ── Routes ─────────────────────────────────────────────────────────────
    private function unreadTotal(): never
    {
        $u = $this->auth->user();
        if (!$u) $this->ok(['count' => 0]);
        $this->ok(['count' => array_sum($this->unreadCounts($u['id']))]);
    }

    private function listConversations(): never
    {
        $u       = $this->me();
        $users   = $this->usersMap();
        $unread  = $this->unreadCounts($u['id']);
        $blocked = array_flip($this->blockedIdsFor($u['id']));
        $out     = [];
        foreach ($this->store->all('conversations') as $c) {
            if (!in_array($u['id'], (array)($c['participants'] ?? []), true)) continue;
            $oid = $this->otherId($c, $u['id']);
            $out[] = [
                'id'              => $c['id'],
                'other'           => $this->pub($users[$oid] ?? null),
                'last_preview'    => (string)($c['last_preview'] ?? ''),
                'last_sender_id'  => (string)($c['last_sender_id'] ?? ''),
                'last_message_at' => (string)($c['last_message_at'] ?? ($c['created_at'] ?? '')),
                'unread'          => (int)($unread[$c['id']] ?? 0),
                'blocked'         => isset($blocked[$oid]),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['last_message_at'], $a['last_message_at']));
        $this->ok(['conversations' => $out]);
    }

    private function openConversation(array $input): never
    {
        $u   = $this->me();
        $oid = trim((string)($input['user_id'] ?? ''));
        if ($oid === '' || $oid === $u['id']) $this->fail('Destinataire invalide.');
        $other = $this->findById('users', $oid);
        if (!$other || in_array(strtolower((string)($other['account_status'] ?? 'active')), ['banned', 'suspended'], true)) {
            $this->fail('Utilisateur introuvable.', 404);
        }
        if ($this->isBlocked($u['id'], $oid)) $this->fail('Vous ne pouvez pas écrire à cet utilisateur.', 403);

        foreach ($this->store->all('conversations') as $c) {
            $p = (array)($c['participants'] ?? []);
            if (in_array($u['id'], $p, true) && in_array($oid, $p, true)) {
                $this->ok(['conversation' => ['id' => $c['id'], 'other' => $this->pub($other)]]);
            }
        }
        if (!$this->mayMessage($u['id'], $other)) $this->fail('Cette personne n’accepte pas de nouveaux messages.', 403);
        $conv = [
            'id' => $this->newId('conv_'), 'type' => 'direct', 'participants' => [$u['id'], $oid],
            'created_at' => date('c'), 'last_message_at' => date('c'), 'last_preview' => '', 'last_sender_id' => '',
        ];
        $this->store->insert('conversations', $conv);
        $this->ok(['conversation' => ['id' => $conv['id'], 'other' => $this->pub($other)]], 201);
    }

    private function thread(string $convId): never
    {
        $u    = $this->me();
        $conv = $this->conversationFor($convId, $u['id']);
        $oid  = $this->otherId($conv, $u['id']);
        $since = (string)($_GET['since'] ?? '');
        $msgs = [];
        foreach ($this->store->all('messages') as $msg) {
            if (($msg['conversation_id'] ?? '') !== $convId) continue;
            if ($since !== '' && strcmp((string)($msg['created_at'] ?? ''), $since) <= 0 && empty($_GET['full'])) continue;
            $msgs[] = [
                'id'         => $msg['id'],
                'sender_id'  => $msg['sender_id'] ?? '',
                'content'    => !empty($msg['deleted']) ? '' : (string)($msg['content'] ?? ''),
                'image_url'  => !empty($msg['deleted']) ? '' : (string)($msg['image_url'] ?? ''),
                'deleted'    => !empty($msg['deleted']),
                'created_at' => $msg['created_at'] ?? null,
            ];
        }
        usort($msgs, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));
        $msgs = array_slice($msgs, -200);

        $otherReadAt = '';
        foreach ($this->store->all('message_reads') as $r) {
            if (($r['conversation_id'] ?? '') === $convId && ($r['user_id'] ?? '') === $oid) { $otherReadAt = (string)($r['last_read_at'] ?? ''); break; }
        }
        $this->ok([
            'messages'      => $msgs,
            'other_read_at' => $otherReadAt,
            'blocked'       => $this->isBlocked($u['id'], $oid),
            'other'         => $this->pub($this->findById('users', $oid)),
        ]);
    }

    private function send(string $convId, array $input): never
    {
        $u    = $this->me();
        $conv = $this->conversationFor($convId, $u['id']);
        $oid  = $this->otherId($conv, $u['id']);
        if ($this->isBlocked($u['id'], $oid)) $this->fail('Vous ne pouvez plus échanger avec cet utilisateur.', 403);
        $recipient = $this->findById('users', $oid);
        if (!$recipient) $this->fail('Destinataire introuvable.', 404);
        if (!$this->mayMessage($u['id'], $recipient, $convId)) $this->fail('Cette personne n’accepte pas de nouveaux messages.', 403);

        $text = $this->text($input['content'] ?? '', 2000);
        $img  = $this->uploadOptional('image', 'messages') ?? '';
        if ($text === '' && $img === '') $this->fail('Écrivez un message ou joignez une image.');

        $msg = [
            'id' => $this->newId('msg_'), 'conversation_id' => $convId, 'sender_id' => $u['id'],
            'content' => $text, 'image_url' => $img, 'created_at' => date('c'), 'deleted' => false,
        ];
        $this->store->insert('messages', $msg);
        $preview = $text !== '' ? mb_substr($text, 0, 80) : 'Photo';
        $this->store->updateWhere('conversations', fn($c) => ($c['id'] ?? '') === $convId,
            fn($c) => array_merge($c, ['last_message_at' => $msg['created_at'], 'last_preview' => $preview, 'last_sender_id' => $u['id']]));
        $this->setRead($convId, $u['id']);
        $this->notify($oid, 'message', ($u['display_name'] ?: 'Quelqu’un') . ' vous a envoyé un message.',
            ['link' => '/messages?to=' . $u['id'], 'actor_id' => $u['id'], 'dedupe' => 'msg:' . $convId . ':' . $oid]);
        $this->ok(['message' => [
            'id' => $msg['id'], 'sender_id' => $u['id'], 'content' => $text, 'image_url' => $img,
            'deleted' => false, 'created_at' => $msg['created_at'],
        ]], 201);
    }

    private function setRead(string $convId, string $uid): void
    {
        $now = date('c');
        $found = false;
        $rows = $this->store->all('message_reads');
        foreach ($rows as $i => $r) {
            if (($r['conversation_id'] ?? '') === $convId && ($r['user_id'] ?? '') === $uid) {
                $rows[$i]['last_read_at'] = $now;
                $found = true;
                break;
            }
        }
        if (!$found) $rows[] = ['id' => $this->newId('rd_'), 'conversation_id' => $convId, 'user_id' => $uid, 'last_read_at' => $now];
        $this->store->replace('message_reads', $rows);
    }

    private function markRead(string $convId): never
    {
        $u = $this->me();
        $this->conversationFor($convId, $u['id']);
        $this->setRead($convId, $u['id']);
        $this->ok();
    }

    private function deleteMessage(string $msgId): never
    {
        $u = $this->me();
        $count = $this->store->updateWhere('messages',
            fn($m) => ($m['id'] ?? '') === $msgId && ($m['sender_id'] ?? '') === $u['id'],
            fn($m) => array_merge($m, ['deleted' => true, 'content' => '', 'image_url' => ''])
        );
        if (!$count) $this->fail('Message introuvable.', 404);
        $this->ok();
    }
}
