<?php
declare(strict_types=1);

namespace Kova\Core;

/**
 * Politique et hachage des mots de passe.
 *
 * Règles (F-02 de l'audit) :
 *  - 10 caractères minimum, 72 octets maximum (limite de bcrypt : au-delà le mot de passe serait
 *    tronqué en silence, ce qui affaiblirait la sécurité à l'insu de l'utilisateur) ;
 *  - au moins deux familles parmi minuscules, majuscules, chiffres, symboles ;
 *  - au moins 5 caractères distincts, ni répétition, ni suite évidente (abcdef, 123456, azerty…) ;
 *  - ne contient ni la partie locale de l'e-mail, ni le pseudo ;
 *  - absent d'une liste embarquée de mots de passe courants (aucun appel externe).
 * Hachage : argon2id si disponible, sinon bcrypt (coût 12). Les anciens hachages sont
 * automatiquement mis à niveau à la connexion suivante.
 */
final class Passwords
{
    public const MIN = 10;
    public const MAX_BYTES = 72;

    /** Mots de passe très courants (en minuscules, sans accents). Comparés aussi sans chiffres/symboles finaux. */
    private const COMMON = [
        '123456','1234567','12345678','123456789','1234567890','0123456789','111111','000000','123123','121212','654321','666666','7777777',
        'password','password1','password12','password123','passw0rd','p@ssw0rd','p@ssword','motdepasse','motdepasse1','monmotdepasse',
        'qwerty','qwertyuiop','qwerty123','qwertz','azerty','azertyuiop','azerty123','azerty1234','azertyui','1q2w3e4r','1qaz2wsx','zaq12wsx','qazwsx','asdfghjkl','zxcvbnm','poiuytreza','mlkjhgfdsq',
        'iloveyou','iloveyou1','jetaime','jetaime1','jetaime123','monamour','loveyou','lovelove','ilovekova','kova','kova123','kova1234','kovakova','kova2026','kova2025',
        'admin','admin123','administrator','administrateur','root','rootroot','toor','letmein','welcome','welcome1','bienvenue','bonjour','bonjour1','bonjour123','salut','salut123','coucou','coucou123','hello123','helloworld','hello',
        'abc123','abcd1234','abcdef','abcdefgh','abcdefghij','aaaaaa','aaaaaaaa','aaaaaaaaaa','a1b2c3d4','test','test123','testtest','tester','user','user123','guest','default','changeme','change','secret','secret123',
        'soleil','soleil1','doudou','chouchou','cheri','cherie','loulou','minou','bebe','princesse','princess','superman','batman','spiderman','pokemon','naruto','dragon','dragonball','football','foot','marseille','psg','barcelona','realmadrid','arsenal','chelsea',
        'cameroun','cameroon','douala','yaounde','douala237','yaounde237','237237237','bamenda','bafoussam','kribi','africa','afrique','lion','lions','indomptables','gamer','gamer123','gamers','mconnect','minecraft','fortnite','freefire','playstation','xbox',
        'monkey','dragon1','master','shadow','sunshine','flower','hunter','ranger','buster','jordan','michael','jennifer','thomas','daniel','maxime','nicolas','julien','camille','marie','sophie','isabelle','david','alexandre','christophe',
        'trustno1','whatever','starwars','matrix','freedom','baseball','superman1','harley','ginger','summer','winter','autumn','spring','orange','banana','cookie','chocolate','chocolat','fromage','pastis','bordeaux','paris','lyon','toulouse',
        'internet','computer','ordinateur','windows','google','facebook','twitter','instagram','snapchat','tiktok','whatsapp','youtube','netflix','amazon','samsung','iphone','android',
        'qwerty1','qwerty12','qwerty1234','password2','password01','pass1234','pass12345','pass123456','motdepasse123','1234qwer','qwer1234','1234abcd','abcd12345','q1w2e3r4','q1w2e3r4t5','2wsx3edc','1qazxsw2','zxcvbnm123',
        '00000000','11111111','22222222','12341234','11223344','1122334455','123321','12344321','987654321','9876543210','147258369','159753','159357','741852963','13579','24680','112233','445566','696969','420420','101010','1010101010',
    ];

    /** @return string|null message d'erreur, ou null si le mot de passe est accepté */
    public static function validate(string $password, string $email = '', string $displayName = ''): ?string
    {
        $len = strlen($password);
        if ($len < self::MIN) return 'Le mot de passe doit contenir au moins ' . self::MIN . ' caractères.';
        if ($len > self::MAX_BYTES) return 'Le mot de passe est trop long (' . self::MAX_BYTES . ' caractères maximum).';
        if (preg_match('/[\x00-\x1f\x7f]/', $password)) return 'Le mot de passe contient des caractères non autorisés.';

        $classes = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $re) {
            if (preg_match($re, $password)) $classes++;
        }
        if ($classes < 2) return 'Mélangez au moins deux types de caractères (lettres minuscules, majuscules, chiffres, symboles).';

        $chars = preg_split('//u', $password, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count(array_unique($chars)) < 5) return 'Ce mot de passe est trop répétitif : utilisez davantage de caractères différents.';

        $flat = self::normalize($password);
        if (self::isSequence($flat)) return 'Évitez les suites évidentes (abcdef, 123456, azerty…).';
        if (self::isRepeatedBlock($flat)) return 'Évitez de répéter un motif court (abcabcabc…).';

        if (self::isCommon($flat)) return 'Ce mot de passe est trop courant. Choisissez-en un moins prévisible.';

        // Ne doit contenir ni l'e-mail, ni le pseudo, ni un de leurs mots (Cabrel, Tamo…) de 4 lettres ou plus.
        $tokens = [];
        $local = (string)(explode('@', $email)[0] ?? '');
        $tokens[] = preg_replace('/[^a-z0-9]/', '', strtolower($local)) ?? '';
        foreach (preg_split('/[^a-z0-9]+/i', strtolower($local)) ?: [] as $t) $tokens[] = $t;
        $name = function_exists('mb_strtolower') ? mb_strtolower($displayName, 'UTF-8') : strtolower($displayName);
        $tokens[] = self::normalize($name);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $name) ?: [] as $t) $tokens[] = self::normalize($t);
        foreach (array_unique($tokens) as $t) {
            if (strlen($t) >= 4 && str_contains($flat, $t)) return 'Le mot de passe ne doit pas contenir votre pseudo ni votre adresse e-mail.';
        }
        return null;
    }

    private static function normalize(string $s): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = is_string($t) && $t !== '' ? $t : $s;
        }
        return preg_replace('/[^a-z0-9@]/', '', strtolower($s)) ?? '';
    }

    private static function isCommon(string $flat): bool
    {
        static $set = null;
        $set ??= array_flip(self::COMMON);
        if (isset($set[$flat])) return true;
        // « password2026! », « azerty12 » : on retire chiffres et symboles de fin, et de début.
        $stripped = preg_replace('/[0-9@]+$/', '', $flat) ?? $flat;
        if ($stripped !== '' && isset($set[$stripped])) return true;
        $stripped2 = preg_replace('/^[0-9@]+/', '', $stripped) ?? $stripped;
        if (strlen($stripped2) >= 4 && isset($set[$stripped2])) return true;
        // Substitutions « leet » simples : p@ssw0rd → password
        $leet = strtr($flat, ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't']);
        return isset($set[$leet]);
    }

    private static function isSequence(string $flat): bool
    {
        if (strlen($flat) < 6) return false;
        $rows = ['abcdefghijklmnopqrstuvwxyz', '0123456789', 'azertyuiopqsdfghjklmwxcvbn', 'qwertyuiopasdfghjklzxcvbnm', '9876543210', 'zyxwvutsrqponmlkjihgfedcba'];
        foreach ($rows as $row) {
            if (str_contains($row, $flat)) return true;
        }
        return false;
    }

    private static function isRepeatedBlock(string $flat): bool
    {
        $n = strlen($flat);
        for ($size = 1; $size <= 4; $size++) {
            if ($n >= $size * 3 && $n % $size === 0 && str_repeat(substr($flat, 0, $size), intdiv($n, $size)) === $flat) return true;
        }
        return false;
    }

    // ── Hachage ────────────────────────────────────────────────────────────
    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function options(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]   // recommandation OWASP
            : ['cost' => 12];
    }

    public static function hash(string $password): string
    {
        $h = password_hash($password, self::algo(), self::options());
        return $h !== false ? $h : password_hash($password, PASSWORD_DEFAULT);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo(), self::options());
    }

    /**
     * Vérification à durée constante : si le compte n'existe pas, on vérifie quand même contre un
     * hachage factice pour que le temps de réponse ne révèle pas l'existence de l'adresse.
     */
    public static function verify(string $password, ?string $hash): bool
    {
        static $dummy = null;
        if ($hash === null || $hash === '') {
            $dummy ??= password_hash('kova-dummy-password', self::algo(), self::options()) ?: '';
            password_verify($password, $dummy);
            return false;
        }
        return password_verify($password, $hash);
    }
}
