<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    $log_file = dirname(__DIR__) . '/logs/email_' . date('Y-m-d') . '.log';
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

// Supprimer l'affichage du chemin racine qui pourrait révéler la structure du serveur
// echo $root_path; // Cette ligne a été supprimée pour des raisons de sécurité

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
    header("Location: ../login.php");
    exit;
}

// Vérifier si l'ID de la facture est fourni et valide
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    logMessage("ID de facture invalide: " . ($_GET['id'] ?? 'non défini'), 'ERROR');
    $_SESSION['error'] = "ID de facture invalide.";
    header('Location: index.php');
    exit;
}

$invoice_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
logMessage("Traitement de la facture #$invoice_id");

try {
    // Connexion à la base de données avec gestion d'erreur améliorée
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Impossible de se connecter à la base de données.");
    }
    
    // Récupérer les informations de la facture et du client avec des contrôles de sécurité
    $query = "SELECT f.*, c.nom, c.prenom, c.email, c.telephone 
              FROM factures f 
              LEFT JOIN clients c ON f.ID_Client = c.id 
              WHERE f.id = :id";
    $stmt = $db->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Erreur de préparation de la requête SQL.");
    }
    
    $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
    $success = $stmt->execute();
    
    if (!$success) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Erreur d'exécution SQL: " . $errorInfo[2]);
    }

    if ($stmt->rowCount() == 0) {
        logMessage("Facture #$invoice_id introuvable", 'ERROR');
        throw new Exception("Facture introuvable.");
    }

    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    logMessage("Facture #$invoice_id récupérée: " . $facture['Numero_Facture']);

    // Vérifier si le client a une adresse email
    if (empty($facture['email'])) {
        logMessage("Client sans email pour la facture #$invoice_id", 'ERROR');
        throw new Exception("Le client n'a pas d'adresse email enregistrée.");
    }
    
    // Vérifier la validité de l'email
    if (!filter_var($facture['email'], FILTER_VALIDATE_EMAIL)) {
        logMessage("Email client invalide: " . $facture['email'], 'ERROR');
        throw new Exception("L'adresse email du client est invalide.");
    }

    // Générer le PDF de la facture
    $pdf_path = null;
    if (function_exists('generateInvoicePDF')) {
        logMessage("Génération du PDF pour la facture #$invoice_id");
        $pdf_path = generateInvoicePDF($invoice_id, $db);
        
        if (!$pdf_path || !file_exists($pdf_path)) {
            logMessage("Échec de génération du PDF pour la facture #$invoice_id", 'ERROR');
            // Ne pas lancer d'exception, continuer sans pièce jointe
        } else {
            logMessage("PDF généré avec succès: $pdf_path");
        }
    } else {
        logMessage("Fonction generateInvoicePDF non disponible", 'WARNING');
    }

    // Configuration de PHPMailer avec gestion d'erreurs améliorée
    $mail = new PHPMailer(true);
    
    try {
        // Configuration du serveur
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ayoubbellahcen6@gmail.com';
        $mail->Password   = 'mpxvrinrbmpdzinw'; // Idéalement, utilisez des variables d'environnement
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
        $mail->setFrom('ayoubbellahcen6@gmail.com', 'Auto Gestion');
        $mail->addAddress($facture['email'], $facture['prenom'] . ' ' . $facture['nom']);

        $mail->addReplyTo($facture['email'], 'Service Client');
        
        // Ajouter un BCC pour garder une copie
        $mail->addBCC('ayoubbellahcen6@gmail.com', 'Archives Factures');
        
        logMessage("Destinataires configurés: " . $facture['email']);

        // Pièce jointe (si le PDF a été généré)
        if ($pdf_path && file_exists($pdf_path)) {
            $mail->addAttachment($pdf_path, 'Facture_' . $facture['Numero_Facture'] . '.pdf');
            logMessage("Pièce jointe ajoutée: Facture_" . $facture['Numero_Facture'] . '.pdf');
        }

        // Contenu
        $mail->isHTML(true);
        $mail->Subject = 'Votre facture ' . $facture['Numero_Facture'] . ' - Auto Gestion';
        
        // Préparer les données pour le template d'email
        $emailData = [
            'prenom' => htmlspecialchars($facture['prenom']),
            'nom' => htmlspecialchars($facture['nom']),
            'numero_facture' => htmlspecialchars($facture['Numero_Facture']),
            'montant' => number_format($facture['Montant_Total_HT'], 2, ',', ' '),
            'annee' => date('Y')
        ];
        
        // Corps du message HTML avec template amélioré
        $mail->Body = getEmailTemplate($emailData);
        
        // Version texte brut alternative
        $mail->AltBody = getPlainTextEmail($emailData);
        
        logMessage("Contenu de l'email préparé");

        // Envoyer l'email
        $mail->send();
        logMessage("Email envoyé avec succès à " . $facture['email']);
        
        // Enregistrer l'envoi dans la base de données
        $updateQuery = "UPDATE factures SET Email_Sent = 1, Email_Sent_Date = :date ,Statut_Facture = 'Émise' WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $currentDate = date('Y-m-d H:i:s');
        $updateStmt->bindParam(':date', $currentDate);
        $updateStmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
        $updateSuccess = $updateStmt->execute();
        
        if (!$updateSuccess) {
            logMessage("Échec de mise à jour du statut d'envoi dans la base de données", 'WARNING');
        } else {
            logMessage("Statut d'envoi mis à jour dans la base de données");
        }
        
        // Supprimer le fichier PDF temporaire si nécessaire
        if ($pdf_path && file_exists($pdf_path) && isset($_GET['delete_pdf']) && $_GET['delete_pdf'] == 1) {
            if (unlink($pdf_path)) {
                logMessage("Fichier PDF temporaire supprimé: $pdf_path");
            } else {
                logMessage("Échec de suppression du fichier PDF temporaire: $pdf_path", 'WARNING');
            }
        }
        
        // Rediriger avec un message de succès
        $_SESSION['success'] = "La facture a été envoyée par email avec succès à " . $facture['email'];
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        throw new Exception("Erreur lors de l'envoi de l'email: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    // Journaliser l'erreur
    logMessage('Erreur: ' . $e->getMessage(), 'ERROR');
    
    // Afficher un message d'erreur
    $_SESSION['error'] = "L'email n'a pas pu être envoyé. Erreur: " . $e->getMessage();
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
            .logo { max-width: 150px; height: auto; margin-bottom: 10px; }
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
                <h2>Votre facture est disponible</h2>
            </div>
            <div class="content">
                <p>Bonjour ' . $data['prenom'] . ' ' . $data['nom'] . ',</p>
                <p>Nous vous informons que votre facture est maintenant disponible.</p>
                
                <div class="info-box">
                    <p><strong>Numéro de facture:</strong> <span class="highlight">' . $data['numero_facture'] . '</span></p>
                    <p><strong>Montant:</strong> <span class="highlight">' . $data['montant'] . ' DH HT</span></p>
                </div>
                
                <p>Vous trouverez votre facture en pièce jointe de cet email au format PDF.</p>
                <p>Pour toute question concernant cette facture, n\'hésitez pas à nous contacter en répondant directement à cet email ou en utilisant notre formulaire de contact.</p>
                
                <p style="text-align: center; margin: 25px 0;">
                    <a href="https://www.autogestion.com/contact" class="button">Nous contacter</a>
                </p>
                
                <p>Cordialement,<br>L\'équipe d\'Auto Gestion</p>
            </div>
            <div class="footer">
                <p>© ' . $data['annee'] . ' Auto Gestion - Tous droits réservés</p>
                <p>123 Rue Exemple, 75000 Paris, France</p>
                <p><a href="https://www.autogestion.com/confidentialite">Politique de confidentialité</a></p>
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

Nous vous informons que votre facture est maintenant disponible.

Numéro de facture: ' . $data['numero_facture'] . '
Montant: ' . $data['montant'] . ' DH HT
Date d\'échéance: ' . $data['date_echeance'] . '

Vous trouverez votre facture en pièce jointe de cet email au format PDF.

Pour toute question concernant cette facture, n\'hésitez pas à nous contacter.

Cordialement,
L\'équipe d\'Auto Gestion
www.autogestion.com

© ' . $data['annee'] . ' Auto Gestion - Tous droits réservés
123 Rue Exemple, 75000 Paris, France';
}
?>
