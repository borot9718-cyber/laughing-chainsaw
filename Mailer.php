<?php
declare(strict_types=1);

namespace Kova\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

require_once dirname(__DIR__, 2) . '/vendor/phpmailer/src/Exception.php';
require_once dirname(__DIR__, 2) . '/vendor/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__, 2) . '/vendor/phpmailer/src/SMTP.php';

final class Mailer
{
    public function __construct(private Env $env) {}

    private function make(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$this->env->get('MAIL_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$this->env->get('MAIL_USERNAME', '');
        $mail->Password   = (string)$this->env->get('MAIL_PASSWORD', '');
        $mail->SMTPSecure = (string)$this->env->get('MAIL_ENCRYPTION', 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$this->env->get('MAIL_PORT', 587);
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10;      // un SMTP injoignable ne doit pas bloquer la requête pendant 5 min
        $mail->SMTPKeepAlive = false;
        $mail->setFrom(
            (string)$this->env->get('MAIL_FROM_ADDRESS', 'noreply@kova.app'),
            (string)$this->env->get('MAIL_FROM_NAME', 'KOVA')
        );
        return $mail;
    }

    public function sendVerification(string $toEmail, string $code): bool
    {
        $appName = (string)$this->env->get('APP_NAME', 'KOVA');
        try {
            $mail = $this->make();
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = "Votre code de vérification {$code} — {$appName}";
            $mail->Body    = $this->templateVerification($code, $appName);
            $mail->AltBody = "Bienvenue sur {$appName} ! Votre code de vérification est : {$code}. Il est valable 15 minutes.";
            $mail->send();
            return true;
        } catch (MailException $e) {
            error_log('[KOVA Mailer] Erreur vérification : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Avis de sécurité : nouvelle connexion, changement de mot de passe, activation/désactivation
     * de la double authentification, tentative d'inscription avec votre adresse…
     * Les textes sont échappés : aucun contenu utilisateur n'est interprété comme du HTML.
     */
    public function sendSecurityNotice(string $toEmail, string $subject, string $headline, string $message): bool
    {
        $appName = (string)$this->env->get('APP_NAME', 'KOVA');
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head><body style="font-family:Segoe UI,sans-serif;background:#0b1020;color:#e0e0e0;margin:0;padding:40px 20px">'
            . '<div style="max-width:520px;margin:auto;background:#111a31;border-radius:16px;padding:36px">'
            . '<div style="font-size:1.6rem;font-weight:800;color:#7c3aed;margin-bottom:18px">K ' . $e($appName) . '</div>'
            . '<h1 style="color:#a78bfa;font-size:1.3rem;margin:0 0 10px">' . $e($headline) . '</h1>'
            . '<p style="color:#b0b8d0;line-height:1.6">' . nl2br($e($message)) . '</p>'
            . '<p style="color:#8f99b5;line-height:1.6;font-size:.9rem">Si ce n’était pas vous, changez immédiatement votre mot de passe et déconnectez tous vos appareils depuis Paramètres › Sécurité.</p>'
            . '<div style="margin-top:22px;font-size:12px;color:#4a5568">© ' . $e($appName) . ' — Message de sécurité automatique.</div></div></body></html>';
        try {
            $mail = $this->make();
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject . ' — ' . $appName;
            $mail->Body    = $html;
            $mail->AltBody = $headline . "\n\n" . $message;
            $mail->send();
            return true;
        } catch (MailException $ex) {
            error_log('[KOVA Mailer] Erreur avis sécurité : ' . $ex->getMessage());
            return false;
        }
    }

    public function sendPasswordReset(string $toEmail, string $token): bool
    {
        $appUrl  = rtrim((string)$this->env->get('APP_URL', 'https://your-domain.example'), '/');
        $link    = $appUrl . '/reinitialiser-mot-de-passe?token=' . urlencode($token);
        $appName = (string)$this->env->get('APP_NAME', 'KOVA');
        try {
            $mail = $this->make();
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = "Réinitialisation de mot de passe — {$appName}";
            $mail->Body    = $this->templatePasswordReset($link, $appName);
            $mail->AltBody = "Réinitialisez votre mot de passe : {$link} (lien valable 1 heure)";
            $mail->send();
            return true;
        } catch (MailException $e) {
            error_log('[KOVA Mailer] Erreur reset : ' . $e->getMessage());
            return false;
        }
    }

    public function sendSupportAlert(string $toEmail, array $user, array $ticket): bool
    {
        try {
            $mail=$this->make(); $mail->addAddress($toEmail); $mail->isHTML(true);
            $appName=(string)$this->env->get('APP_NAME','KOVA');
            $mail->Subject='Nouvelle demande utilisateur — '.$appName;
            $mail->Body='<h2>Nouvelle demande d’assistance</h2><p><strong>Utilisateur :</strong> '.htmlspecialchars((string)($user['display_name']??''),ENT_QUOTES,'UTF-8').' ('.htmlspecialchars((string)($user['email']??''),ENT_QUOTES,'UTF-8').')</p><p><strong>Type :</strong> '.htmlspecialchars((string)($ticket['category']??''),ENT_QUOTES,'UTF-8').'</p><p><strong>Sujet :</strong> '.htmlspecialchars((string)($ticket['subject']??''),ENT_QUOTES,'UTF-8').'</p><p>'.nl2br(htmlspecialchars((string)($ticket['message']??''),ENT_QUOTES,'UTF-8')).'</p><p>Cette demande est un canal entrant : l’administration ne répond pas directement à l’utilisateur dans une conversation.</p>';
            $mail->AltBody='Nouvelle demande '.$ticket['id'].' de '.$user['email'].' — '.$ticket['subject']; $mail->send(); return true;
        } catch (MailException $e) { error_log('[KOVA Mailer] Support alert : '.$e->getMessage()); return false; }
    }

    public function sendReportAlert(string $toEmail, array $user, array $report): bool
    {
        try {
            $mail=$this->make(); $mail->addAddress($toEmail); $mail->isHTML(true);
            $appName=(string)$this->env->get('APP_NAME','KOVA');
            $mail->Subject='Nouveau signalement — '.$appName;
            $mail->Body='<h2>Nouveau signalement</h2><p><strong>Utilisateur :</strong> '.htmlspecialchars((string)($user['email']??''),ENT_QUOTES,'UTF-8').'</p><p><strong>Type :</strong> '.htmlspecialchars((string)($report['target_type']??''),ENT_QUOTES,'UTF-8').' · <strong>ID :</strong> '.htmlspecialchars((string)($report['target_id']??''),ENT_QUOTES,'UTF-8').'</p><p><strong>Motif :</strong> '.htmlspecialchars((string)($report['reason']??''),ENT_QUOTES,'UTF-8').'</p><p>'.nl2br(htmlspecialchars((string)($report['details']??''),ENT_QUOTES,'UTF-8')).'</p>';
            $mail->AltBody='Nouveau signalement '.$report['id'].' — '.$report['reason']; $mail->send(); return true;
        } catch (MailException $e) { error_log('[KOVA Mailer] Report alert : '.$e->getMessage()); return false; }
    }

    public function sendSupportStatus(string $toEmail, array $ticket, string $statusLabel): bool
    {
        try {
            $mail=$this->make(); $mail->addAddress($toEmail); $mail->isHTML(true);
            $appName=(string)$this->env->get('APP_NAME','KOVA');
            $mail->Subject='Mise à jour de votre demande — '.$appName;
            $mail->Body='<h2>Votre demande a été mise à jour</h2><p>Votre demande <strong>'.htmlspecialchars((string)($ticket['subject']??''),ENT_QUOTES,'UTF-8').'</strong> est maintenant : <strong>'.htmlspecialchars($statusLabel,ENT_QUOTES,'UTF-8').'</strong>.</p><p>L’administration ne répond pas directement dans cette conversation. Pour toute nouvelle préoccupation, créez une nouvelle demande depuis KOVA.</p>';
            $mail->AltBody='Votre demande '.$ticket['id'].' est maintenant '.$statusLabel.'. L’administration ne répond pas directement dans cette conversation.'; $mail->send(); return true;
        } catch (MailException $e) { error_log('[KOVA Mailer] Support status : '.$e->getMessage()); return false; }
    }

    private function templateVerification(string $code, string $appName): string
    {
        return <<<HTML
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>body{font-family:'Segoe UI',sans-serif;background:#0b1020;color:#e0e0e0;margin:0;padding:40px 20px}
.card{max-width:520px;margin:auto;background:#111a31;border-radius:16px;padding:40px;text-align:center}
h1{color:#a78bfa;font-size:1.5rem;margin:0 0 8px}
p{color:#b0b8d0;line-height:1.6}
.btn{display:inline-block;margin:24px 0;padding:14px 32px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:1rem}
.code{display:inline-block;margin:22px 0;padding:14px 26px;background:#0b1020;border:1px solid #7c3aed;border-radius:12px;color:#fff;font-size:2.2rem;font-weight:800;letter-spacing:.35em;text-indent:.35em}
.footer{margin-top:24px;font-size:12px;color:#4a5568}
.brand{font-size:1.8rem;font-weight:800;color:#7c3aed;letter-spacing:-1px;margin-bottom:24px;display:block}
</style></head><body>
<div class="card">
<span class="brand">K KOVA</span>
<h1>Vérifiez votre adresse e-mail</h1>
<p>Bienvenue sur <strong>{$appName}</strong> ! Saisissez ce code dans l'application pour activer votre compte :</p>
<div class="code">{$code}</div>
<p>Ce code est valable <strong>15 minutes</strong>. Ne le communiquez à personne. Si vous n'avez pas créé de compte, ignorez cet e-mail.</p>
<div class="footer">© {$appName} — Cet e-mail a été envoyé automatiquement.</div>
</div></body></html>
HTML;
    }

    private function templatePasswordReset(string $link, string $appName): string
    {
        return <<<HTML
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>body{font-family:'Segoe UI',sans-serif;background:#0b1020;color:#e0e0e0;margin:0;padding:40px 20px}
.card{max-width:520px;margin:auto;background:#111a31;border-radius:16px;padding:40px;text-align:center}
h1{color:#a78bfa;font-size:1.5rem;margin:0 0 8px}
p{color:#b0b8d0;line-height:1.6}
.btn{display:inline-block;margin:24px 0;padding:14px 32px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:1rem}
.footer{margin-top:24px;font-size:12px;color:#4a5568}
.brand{font-size:1.8rem;font-weight:800;color:#7c3aed;letter-spacing:-1px;margin-bottom:24px;display:block}
</style></head><body>
<div class="card">
<span class="brand">K KOVA</span>
<h1>Réinitialisation de mot de passe</h1>
<p>Vous avez demandé à réinitialiser votre mot de passe sur <strong>{$appName}</strong>.</p>
<a class="btn" href="{$link}">Réinitialiser mon mot de passe</a>
<p>Ce lien est valable <strong>1 heure</strong>. Si vous n'avez pas fait cette demande, ignorez cet e-mail.</p>
<div class="footer">© {$appName} — Cet e-mail a été envoyé automatiquement.</div>
</div></body></html>
HTML;
    }
}
