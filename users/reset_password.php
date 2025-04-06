<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Activer l'affichage des erreurs pour le développement uniquement
// À commenter en production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Journalisation
function logMessage($message, $type = 'INFO') {
    $log_file = dirname(__DIR__) . '/logs/password_reset_' . date('Y-m-d') . '.log';
    $log_dir = dirname($log_file);
    
    // Créer le répertoire de logs s'il n'existe pas
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $log_file, 
        "[$timestamp] [$type] $message" . PHP_EOL, 
        FILE_APPEND
    );
}

// Fonction pour générer un mot de passe aléatoire
function generateRandomPassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $password = '';
    $max = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    
    return $password;
}

// Inclure les fichiers de configuration et de fonctions
$requiredFiles = [
    '/config/database.php' => 'Configuration de la base de données',
    '/includes/functions.php' => 'Fonctions utilitaires',
    '/PHPMailer-6.9.3/src/Exception.php' => 'PHPMailer Exception',
    '/PHPMailer-6.9.3/src/PHPMailer.php' => 'PHPMailer Core',
    '/PHPMailer-6.9.3/src/SMTP.php' => 'PHPMailer SMTP'
];

foreach ($requiredFiles as $file => $description) {
    $filePath = $root_path . $file;
    if (file_exists($filePath)) {
        require_once $filePath;
        logMessage("Fichier chargé: $description");
    } else {
        logMessage("Fichier manquant: $description ($filePath)", 'ERROR');
        $_SESSION['error'] = "Configuration système incomplète. Veuillez contacter l'administrateur.";
        header('Location: index.php');
        exit;
    }
}

// Importer les classes PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    logMessage("Tentative d'accès non autorisé - utilisateur non connecté", 'SECURITY');
    // Pour le développement, créer un utilisateur factice
    $_SESSION['user_id'] = 1;
    logMessage("Utilisateur factice créé pour le développement", 'DEV');
}

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("Traitement de la réinitialisation de mot de passe");
    
    // Récupérer l'ID du technicien
    $id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : 0;
    
    // Valider l'ID
    if ($id <= 0) {
        logMessage("ID de technicien invalide: $id", 'ERROR');
        $_SESSION['error'] = "ID de technicien invalide";
        header('Location: index.php');
        exit;
    }
    
    logMessage("Réinitialisation du mot de passe pour le technicien ID: $id");
    
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        if (!$db) {
            throw new Exception("Impossible de se connecter à la base de données.");
        }
        
        logMessage("Connexion à la base de données établie");
        
        // Récupérer les informations du technicien
        $query = "SELECT nom, prenom, email, user_name FROM technicien WHERE id = :id LIMIT 1";
        $stmt = $db->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Erreur de préparation de la requête SQL.");
        }
        
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $success = $stmt->execute();
        
        if (!$success) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL: " . $errorInfo[2]);
        }
        
        if ($stmt->rowCount() == 0) {
            logMessage("Technicien ID: $id introuvable", 'ERROR');
            throw new Exception("Ce technicien n'existe pas");
        }
        
        $technicien = $stmt->fetch(PDO::FETCH_ASSOC);
        logMessage("Informations du technicien récupérées: " . $technicien['prenom'] . " " . $technicien['nom']);
        
        // Vérifier si l'email est valide
        if (empty($technicien['email']) || !filter_var($technicien['email'], FILTER_VALIDATE_EMAIL)) {
            logMessage("Email invalide pour le technicien ID: $id - " . $technicien['email'], 'WARNING');
        }
        
        // Générer un nouveau mot de passe
        $new_password = generateRandomPassword();
        logMessage("Nouveau mot de passe généré pour " . $technicien['user_name']);
        
        // Hasher le mot de passe
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Mettre à jour le mot de passe dans la base de données
        $update_query = "UPDATE technicien SET password = :password WHERE id = :id";
        $update_stmt = $db->prepare($update_query);
        
        if (!$update_stmt) {
            throw new Exception("Erreur de préparation de la requête SQL de mise à jour.");
        }
        
        $update_stmt->bindParam(':password', $hashed_password);
        $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $update_success = $update_stmt->execute();
        
        if (!$update_success) {
            $errorInfo = $update_stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL (mise à jour): " . $errorInfo[2]);
        }
        
        logMessage("Mot de passe mis à jour dans la base de données");
        
        // Envoyer un email avec le nouveau mot de passe
        $mail = new PHPMailer(true);
        
        try {
            // Configuration du serveur
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'ayoubbellahcen6@gmail.com';
            $mail->Password   = 'mpxvrinrbmpdzinw';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            
            // Activer le débogage SMTP pour le développement
            $mail->SMTPDebug  = 0; // 0 = désactivé, 1 = messages client, 2 = messages client et serveur
            $mail->Debugoutput = function($str, $level) {
                logMessage("SMTP DEBUG [$level]: $str", 'DEBUG');
            };
            
            // Définir un timeout pour éviter les blocages
            $mail->Timeout = 30; // 30 secondes
            
            logMessage("Configuration SMTP terminée");
            
            // Destinataires
            $mail->setFrom('ayoubbellahcen6@gmail.com', 'SAS Réparation Auto');
            $mail->addAddress($technicien['email'], $technicien['prenom'] . ' ' . $technicien['nom']);
            $mail->addReplyTo('ayoubbellahcen6@gmail.com', 'Service Client');
            
            // Ajouter un BCC pour garder une copie
            $mail->addBCC('ayoubbellahcen6@gmail.com', 'Archives Comptes');
            
            logMessage("Destinataires configurés: " . $technicien['email']);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Réinitialisation de votre mot de passe - SAS Réparation Auto';
            
            // Préparer les données pour le template d'email
            $emailData = [
                'prenom' => htmlspecialchars($technicien['prenom']),
                'nom' => htmlspecialchars($technicien['nom']),
                'username' => htmlspecialchars($technicien['user_name']),
                'password' => htmlspecialchars($new_password),
                'annee' => date('Y')
            ];
            
            // Corps du message HTML
            $mail->Body = getEmailTemplate($emailData);
            
            // Version texte brut alternative
            $mail->AltBody = getPlainTextEmail($emailData);
            
            logMessage("Contenu de l'email préparé");
            
            // Envoyer l'email
            $mail->send();
            logMessage("Email de réinitialisation envoyé avec succès à " . $technicien['email']);
            
            $_SESSION['success'] = "Le mot de passe a été réinitialisé avec succès et envoyé par email";
        } catch (Exception $e) {
            logMessage("Échec de l'envoi de l'email: " . $e->getMessage(), 'ERROR');
            $_SESSION['success'] = "Le mot de passe a été réinitialisé avec succès mais l'envoi de l'email a échoué";
        }
    } catch (Exception $e) {
        logMessage("Erreur: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = "Une erreur est survenue: " . $e->getMessage();
    }
    
    // Rediriger vers la page des techniciens
    header('Location: index.php');
    exit;
} else {
    // Si la méthode n'est pas POST, rediriger vers la page des techniciens
    logMessage("Tentative d'accès direct au script sans soumission de formulaire", 'WARNING');
    header('Location: index.php');
    exit;
}

/**
 * Génère le template HTML pour l'email
 * @param array $data Les données à insérer dans le template
 * @return string Le contenu HTML de l'email
 */
function getEmailTemplate($data) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { width: 100%; max-width: 600px; margin: 0 auto; }
            .header { background-color: #4a86e8; padding: 20px; text-align: center; color: white; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .info-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .footer { background-color: #eee; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            .button { display: inline-block; background-color: #4a86e8; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; }
            .button:hover { background-color: #3b78e7; }
            .highlight { color: #4a86e8; font-weight: bold; }
            .warning { color: #e74c3c; font-weight: bold; }
            @media only screen and (max-width: 600px) {
                .container { width: 100%; }
                .content { padding: 10px; }
                .info-box { padding: 10px; margin: 10px 0; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Réinitialisation de votre mot de passe</h2>
            </div>
            <div class="content">
                <p>Bonjour ' . $data['prenom'] . ' ' . $data['nom'] . ',</p>
                <p>Votre mot de passe a été réinitialisé. Voici vos nouveaux identifiants de connexion :</p>
                
                <div class="info-box">
                    <p><strong>Nom d\'utilisateur:</strong> <span class="highlight">' . $data['username'] . '</span></p>
                    <p><strong>Nouveau mot de passe:</strong> <span class="highlight">' . $data['password'] . '</span></p>
                </div>
                
                <p>Nous vous recommandons de changer votre mot de passe dès votre prochaine connexion.</p>
                <p>Pour vous connecter, veuillez visiter notre site web et utiliser les identifiants ci-dessus.</p>
                
                <p class="warning">Si vous n\'avez pas demandé cette réinitialisation, veuillez contacter immédiatement l\'administrateur.</p>
                
                <p style="text-align: center; margin: 25px 0;">
                    <a href="https://www.sasreparation.com/login" class="button">Se connecter</a>
                </p>
                
                <p>Cordialement,<br>L\'équipe de SAS Réparation Auto</p>
            </div>
            <div class="footer">
                <p>© ' . $data['annee'] . ' SAS Réparation Auto - Tous droits réservés</p>
                <p>Ce message est confidentiel. Si vous n\'êtes pas le destinataire prévu, veuillez nous contacter.</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Génère la version texte brut de l'email
 * @param array $data Les données à insérer dans le template
 * @return string Le contenu texte de l'email
 */
function getPlainTextEmail($data) {
    return 'Bonjour ' . $data['prenom'] . ' ' . $data['nom'] . ',

Votre mot de passe a été réinitialisé. Voici vos nouveaux identifiants de connexion :

Nom d\'utilisateur: ' . $data['username'] . '
Nouveau mot de passe: ' . $data['password'] . '

Nous vous recommandons de changer votre mot de passe dès votre prochaine connexion.
Pour vous connecter, veuillez visiter notre site web et utiliser les identifiants ci-dessus.

ATTENTION: Si vous n\'avez pas demandé cette réinitialisation, veuillez contacter immédiatement l\'administrateur.

Cordialement,
L\'équipe de SAS Réparation Auto

© ' . $data['annee'] . ' SAS Réparation Auto - Tous droits réservés
Ce message est confidentiel. Si vous n\'êtes pas le destinataire prévu, veuillez nous contacter.';
}
?>
