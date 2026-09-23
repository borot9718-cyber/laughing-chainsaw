<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

final class KovaClient {
    private string $base; private string $token;
    public function __construct() { $this->base = rtrim((string)studio_env('KOVA_BASE_URL'), '/'); $this->token = (string)studio_env('KOVA_GATEWAY_TOKEN'); }
    public function call(string $path, string $method = 'GET', array $payload = []): array {
        if ($this->base === '' || $this->token === '') throw new RuntimeException('KOVA_BASE_URL ou KOVA_GATEWAY_TOKEN manquant.');
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];
        if ($method !== 'GET') { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>45, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false) throw new RuntimeException('KOVA réseau: ' . $err);
        $data = json_decode($body, true); if (!is_array($data)) throw new RuntimeException('Réponse KOVA invalide.');
        if ($code >= 400) throw new RuntimeException((string)($data['error'] ?? 'KOVA HTTP ' . $code));
        return $data;
    }
    public function handshake(): array { return $this->call('/api/gateway/handshake'); }
    public function style(): array { return $this->call('/api/gateway/publication-style'); }
    public function publish(array $payload): array { return $this->call('/api/gateway/publications', 'POST', $payload); }
}

final class OpenAIProvider {
    private string $key;
    public function __construct() { $this->key = (string)studio_env('OPENAI_API_KEY'); }
    public function text(string $idea, array $options = []): string {
        if ($this->key === '') throw new RuntimeException('OPENAI_API_KEY manquante.');
        $system = 'Tu es le rédacteur éditorial de KOVA. Réponds uniquement avec le texte de publication, sans commentaire supplémentaire. Respecte le ton, la langue et les contraintes.';
        $user = "Idée: {$idea}\nOptions: " . json_encode($options, JSON_UNESCAPED_UNICODE);
        $data = $this->request('https://api.openai.com/v1/chat/completions', ['model'=>studio_env('OPENAI_TEXT_MODEL','gpt-4o-mini'),'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],'temperature'=>0.8]);
        return trim((string)($data['choices'][0]['message']['content'] ?? ''));
    }
    private function request(string $url, array $body): array {
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $data=json_decode((string)$raw,true); if(!is_array($data)||$code>=400) throw new RuntimeException('Fournisseur IA indisponible.'); return $data;
    }
}
