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

/**
 * Génère un mot de passe aléatoire plus sécurisé
 * 
 * @param int $length Longueur du mot de passe
 * @return string Mot de passe généré
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
    $password = '';
    $max = strlen($chars) - 1;
    
    // Utiliser random_int pour une meilleure sécurité que rand()
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
        header('Location: index.php?type=admin');
        exit;
    }
}

// Importer les classes PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Vérifier si l'utilisateur est connecté et a les droits d'administrateur
/* if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    logMessage("Tentative d'accès non autorisé à la réinitialisation de mot de passe", 'SECURITY');
    $_SESSION['error'] = "Vous n'avez pas les droits nécessaires pour effectuer cette action.";
    header('Location: index.php?type=admin');
    exit;
}
 */
// Traiter la demande de réinitialisation de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    logMessage("Traitement de la demande de réinitialisation de mot de passe");
    
    // Récupérer et valider l'ID de l'utilisateur
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    
    if (!$id) {
        logMessage("ID utilisateur invalide: " . $_POST['id'], 'ERROR');
        $_SESSION['error'] = "ID utilisateur invalide.";
        header('Location: index.php?type=admin');
        exit;
    }
    
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        if (!$db) {
            throw new Exception("Impossible de se connecter à la base de données.");
        }
        
        logMessage("Connexion à la base de données établie");
        
        // Vérifier si l'utilisateur existe
        $check_query = "SELECT id, email, nom, prenom, username FROM users WHERE id = :id LIMIT 1";
        $check_stmt = $db->prepare($check_query);
        
        if (!$check_stmt) {
            throw new Exception("Erreur de préparation de la requête SQL.");
        }
        
        $check_stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $success = $check_stmt->execute();
        
        if (!$success) {
            $errorInfo = $check_stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL: " . $errorInfo[2]);
        }
        
        if ($check_stmt->rowCount() === 0) {
            logMessage("Utilisateur ID: $id introuvable", 'ERROR');
            $_SESSION['error'] = "L'utilisateur n'existe pas.";
            header('Location: index.php?type=admin');
            exit;
        }
        
        $user = $check_stmt->fetch(PDO::FETCH_ASSOC);
        logMessage("Informations de l'utilisateur récupérées: " . $user['prenom'] . " " . $user['nom']);
        
        // Vérifier si l'email est valide
        if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            logMessage("Email invalide pour l'utilisateur ID: $id - " . $user['email'], 'WARNING');
        }
        
        // Générer un nouveau mot de passe aléatoire
        $new_password = generateRandomPassword();
        logMessage("Nouveau mot de passe généré pour " . $user['username']);
        
        // Hasher le mot de passe
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Mettre à jour le mot de passe
        $query = "UPDATE users SET password = :password WHERE id = :id";
        $stmt = $db->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Erreur de préparation de la requête SQL de mise à jour.");
        }
        
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $update_success = $stmt->execute();
        
        if (!$update_success) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL (mise à jour): " . $errorInfo[2]);
        }
        
        logMessage("Mot de passe mis à jour dans la base de données");
        $_SESSION['success'] = "Le mot de passe a été réinitialisé avec succès.";
        
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
            $mail->addAddress($user['email'], $user['prenom'] . ' ' . $user['nom']);
            $mail->addReplyTo('ayoubbellahcen6@gmail.com', 'Service Client');
            
            // Ajouter un BCC pour garder une copie
            $mail->addBCC('ayoubbellahcen6@gmail.com', 'Archives Comptes');
            
            logMessage("Destinataires configurés: " . $user['email']);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Réinitialisation de votre mot de passe - SAS Réparation Auto';
            
            // Préparer les données pour le template d'email
            $emailData = [
                'prenom' => htmlspecialchars($user['prenom']),
                'nom' => htmlspecialchars($user['nom']),
                'username' => htmlspecialchars($user['username']),
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
            logMessage("Email de réinitialisation envoyé avec succès à " . $user['email']);
        } catch (Exception $e) {
            logMessage("Échec de l'envoi de l'email: " . $e->getMessage(), 'ERROR');
            $_SESSION['success'] .= " Mais l'envoi de l'email a échoué.";
            // Pour le développement, afficher le mot de passe dans la session
            $_SESSION['success'] .= " Nouveau mot de passe (à envoyer manuellement) : " . $new_password;
        }
        
    } catch (PDOException $e) {
        logMessage("Erreur PDO: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    } catch (Exception $e) {
        logMessage("Erreur: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = "Une erreur est survenue: " . $e->getMessage();
    }
    
    // Rediriger vers la page d'administration
    header('Location: index.php?type=admin');
    exit;
} else {
    logMessage("Tentative d'accès direct au script sans soumission de formulaire", 'WARNING');
    $_SESSION['error'] = "Requête invalide.";
    header('Location: index.php?type=admin');
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