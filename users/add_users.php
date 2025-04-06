<?php
// Démarrer la session
session_start();


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Activer l'affichage des erreurs pour le développement uniquement
// À commenter en production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Fonction de journalisation
function logMessage($message, $type = 'INFO') {
    $log_file = dirname(__DIR__) . '/logs/admin_' . date('Y-m-d') . '.log';
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
    logMessage("Tentative d'accès non autorisé - utilisateur sans droits admin", 'SECURITY');
    $_SESSION['error'] = "Vous n'avez pas les droits nécessaires pour effectuer cette action.";
    header('Location: index.php?type=admin');
    exit;
}
 */

// Traiter le formulaire d'ajout d'utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("Traitement du formulaire d'ajout d'utilisateur");
    
    // Récupérer et valider les données du formulaire
    $username = isset($_POST['username']) ? htmlspecialchars(trim($_POST['username'])) : '';
    $nom = isset($_POST['nom']) ? htmlspecialchars(trim($_POST['nom'])) : '';
    $prenom = isset($_POST['prenom']) ? htmlspecialchars(trim($_POST['prenom'])) : '';
    $email = isset($_POST['email']) ? trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL)) : '';
    $role = isset($_POST['role']) ? htmlspecialchars(trim($_POST['role'])) : '';
    
    logMessage("Données reçues: Username=$username, Nom=$nom, Prénom=$prenom, Email=$email, Role=$role");
    
    // Validation des données
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "Le nom d'utilisateur est obligatoire.";
    }
    
    if (empty($nom)) {
        $errors[] = "Le nom est obligatoire.";
    }
    
    if (empty($prenom)) {
        $errors[] = "Le prénom est obligatoire.";
    }
    
    if (empty($email)) {
        $errors[] = "L'email est obligatoire.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
        logMessage("Email invalide: $email", 'WARNING');
    }
    
    if (empty($role)) {
        $errors[] = "Le rôle est obligatoire.";
    } elseif (!in_array($role, ['admin', 'manager', 'technicien', 'comptable'])) {
        $errors[] = "Le rôle sélectionné n'est pas valide.";
        logMessage("Rôle invalide: $role", 'WARNING');
    }
    
    if (!empty($errors)) {
        logMessage("Validation échouée: " . implode(", ", $errors), 'ERROR');
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: index.php?type=admin');
        exit;
    }
    
    // Si aucune erreur, procéder à l'ajout
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        if (!$db) {
            throw new Exception("Impossible de se connecter à la base de données.");
        }
        
        logMessage("Connexion à la base de données établie");
        
        // Vérifier si le nom d'utilisateur ou l'email existe déjà
        $check_query = "SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email";
        $check_stmt = $db->prepare($check_query);
        
        if (!$check_stmt) {
            throw new Exception("Erreur de préparation de la requête SQL (vérification utilisateur).");
        }
        
        $check_stmt->bindParam(':username', $username);
        $check_stmt->bindParam(':email', $email);
        $success = $check_stmt->execute();
        
        if (!$success) {
            $errorInfo = $check_stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL (vérification utilisateur): " . $errorInfo[2]);
        }
        
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            logMessage("Utilisateur ou email déjà existant: $username, $email", 'WARNING');
            $_SESSION['error'] = "Le nom d'utilisateur ou l'email existe déjà.";
            header('Location: index.php?type=admin');
            exit;
        }
        
        // Générer un mot de passe aléatoire
        $password = generateRandomPassword();
        logMessage("Mot de passe généré pour $username");
        
        // Hacher le mot de passe
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Préparer la requête d'insertion
        $query = "INSERT INTO users (username, password, nom, prenom, email, role, date_creation, actif) 
                VALUES (:username, :password, :nom, :prenom, :email, :role, NOW(), 1)";
        
        $stmt = $db->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Erreur de préparation de la requête SQL d'insertion.");
        }
        
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':nom', $nom);
        $stmt->bindParam(':prenom', $prenom);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':role', $role);
        
        // Exécuter la requête
        $success = $stmt->execute();
        
        if (!$success) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erreur d'exécution SQL (insertion): " . $errorInfo[2]);
        }
        
        // Récupérer l'ID de l'utilisateur inséré
        $user_id = $db->lastInsertId();
        logMessage("Utilisateur inséré avec ID: $user_id");
        
        // Envoyer un email à l'utilisateur avec ses identifiants
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
            $mail->addAddress($email, $prenom . ' ' . $nom);
            $mail->addReplyTo('ayoubbellahcen6@gmail.com', 'Service Client');
            
            // Ajouter un BCC pour garder une copie
            $mail->addBCC('ayoubbellahcen6@gmail.com', 'Archives Comptes');
            
            logMessage("Destinataires configurés: $email");
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Vos identifiants de connexion - SAS Réparation Auto';
            
            // Préparer les données pour le template d'email
            $emailData = [
                'prenom' => htmlspecialchars($prenom),
                'nom' => htmlspecialchars($nom),
                'username' => htmlspecialchars($username),
                'password' => htmlspecialchars($password),
                'role' => htmlspecialchars($role),
                'annee' => date('Y')
            ];
            
            // Corps du message HTML
            $mail->Body = getEmailTemplate($emailData);
            
            // Version texte brut alternative
            $mail->AltBody = getPlainTextEmail($emailData);
            
            logMessage("Contenu de l'email préparé");
            
            // Envoyer l'email
            $mail->send();
            logMessage("Email envoyé avec succès à $email");
            
            $_SESSION['success'] = "L'utilisateur a été ajouté avec succès et ses identifiants ont été envoyés par email.";
        } catch (Exception $e) {
            logMessage("Échec de l'envoi de l'email: " . $e->getMessage(), 'ERROR');
            $_SESSION['success'] = "L'utilisateur a été ajouté avec succès mais l'envoi de l'email a échoué.";
        }
    } catch (Exception $e) {
        logMessage("Erreur: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = "Une erreur est survenue: " . $e->getMessage();
    }
    
    // Rediriger vers la page d'administration
    header('Location: index.php?type=admin');
    exit;
} else {
    // Si la méthode n'est pas POST, rediriger vers la page d'administration
    logMessage("Tentative d'accès direct au script sans soumission de formulaire", 'WARNING');
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
                <h2>Bienvenue chez SAS Réparation Auto</h2>
            </div>
            <div class="content">
                <p>Bonjour ' . $data['prenom'] . ' ' . $data['nom'] . ',</p>
                <p>Votre compte a été créé avec succès. Vous avez été enregistré avec le rôle de <span class="highlight">' . $data['role'] . '</span>.</p>
                <p>Voici vos identifiants de connexion :</p>
                
                <div class="info-box">
                    <p><strong>Nom d\'utilisateur:</strong> <span class="highlight">' . $data['username'] . '</span></p>
                    <p><strong>Mot de passe:</strong> <span class="highlight">' . $data['password'] . '</span></p>
                </div>
                
                <p>Nous vous recommandons de changer votre mot de passe lors de votre première connexion.</p>
                <p>Pour vous connecter, veuillez visiter notre site web et utiliser les identifiants ci-dessus.</p>
                
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

Votre compte a été créé avec succès. Vous avez été enregistré avec le rôle de ' . $data['role'] . '.
Voici vos identifiants de connexion :

Nom d\'utilisateur: ' . $data['username'] . '
Mot de passe: ' . $data['password'] . '

Nous vous recommandons de changer votre mot de passe lors de votre première connexion.
Pour vous connecter, veuillez visiter notre site web et utiliser les identifiants ci-dessus.

Cordialement,
L\'équipe de SAS Réparation Auto

© ' . $data['annee'] . ' SAS Réparation Auto - Tous droits réservés
Ce message est confidentiel. Si vous n\'êtes pas le destinataire prévu, veuillez nous contacter.';
}
?>