<?php
// Démarrer la session
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'ID de la facture est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID de facture non valide");
}

$facture_id = $_GET['id'];

$database = new Database();
$db = $database->getConnection();

// Récupérer les informations de la facture avec les données client
$query = "SELECT f.*, cl.id as client_id,
          CASE 
             WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
             ELSE CONCAT(cl.nom, ' - ', cl.raison_sociale)
          END AS Nom_Client,
          cl.adresse, cl.telephone, cl.email
          FROM factures f
          LEFT JOIN clients cl ON f.ID_Client = cl.id
          WHERE f.id = :id";
          
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $facture_id);
$stmt->execute();
$facture = $stmt->fetch(PDO::FETCH_ASSOC);

// Vérifier si la facture existe
if (!$facture) {
    die("Facture non trouvée");
}

// Récupérer les détails de la facture (articles)
$query_details = "SELECT fd.*, a.designation, a.reference
                  FROM facture_details fd
                  LEFT JOIN articles a ON fd.article_id = a.id
                  WHERE fd.ID_Facture = :id";
$stmt_details = $db->prepare($query_details);
$stmt_details->bindParam(':id', $facture_id);
$stmt_details->execute();
$facture_details = $stmt_details->fetchAll(PDO::FETCH_ASSOC);

// Fonction pour convertir les montants en lettres
function numberToWords($number) {
    $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'];
    $tens = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante-dix', 'quatre-vingt', 'quatre-vingt-dix'];
    $teens = ['dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    
    if ($number == 0) {
        return 'zéro';
    }
    
    $words = '';
    
    // Milliers
    if ($number >= 1000) {
        if (floor($number / 1000) == 1) {
            $words .= 'mille';
        } else {
            $words .= numberToWords(floor($number / 1000)) . ' mille';
        }
        $number %= 1000;
        if ($number > 0) $words .= ' ';
    }
    
    // Centaines
    if ($number >= 100) {
        if (floor($number / 100) == 1) {
            $words .= 'cent';
        } else {
            $words .= $units[floor($number / 100)] . ' cent';
        }
        $number %= 100;
        if ($number > 0) $words .= ' ';
    }
    
    // Dizaines et unités
    if ($number >= 10) {
        if ($number < 20) {
            $words .= $teens[$number - 10];
        } else {
            $words .= $tens[floor($number / 10)];
            if ($number % 10 > 0) {
                if (floor($number / 10) == 7 || floor($number / 10) == 9) {
                    $words .= '-' . $teens[$number % 10];
                } else {
                    $words .= '-' . $units[$number % 10];
                }
            }
        }
    } else if ($number > 0) {
        $words .= $units[$number];
    }
    
    return $words;
}

// Calculer les totaux
$total_ht = $facture['Montant_Total_HT'] ?? 0;
$tva_rate = $facture['Taux_TVA'] ?? 0;
$total_tva = $total_ht * ($tva_rate / 100);
$total_ttc = $total_ht + $total_tva;
$remise = $facture['Remise'] ?? 0;
$total_after_remise = $total_ttc - $remise;

// Convertir le montant total en lettres
$montant_en_lettres = numberToWords(floor($total_after_remise));
if ($montant_en_lettres) {
    $montant_en_lettres = $montant_en_lettres . ' dhs';
}

// Définir le titre de la page avec le numéro de facture
$pageTitle = "Facture " . $facture['Numero_Facture'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 11pt;
            line-height: 1.3;
        }
        .container {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 5mm;
            position: relative;
            min-height: 297mm; /* A4 height */
        }
        .header {
            margin-bottom: 20px;
            text-align: center;
        }
        .company-name {
            font-size: 22pt;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 0;
        }
        .company-name-arabic {
            font-size: 16pt;
            color: #4CAF50;
            margin-top: 0;
        }
        .document-title {
            font-size: 18pt;
            font-weight: bold;
            margin-top: 10px;
            text-align: center;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .info-box {
            border: 1px solid #ccc;
            padding: 5px 10px;
            background-color: #f9f9f9;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .info-label {
            font-weight: bold;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .totals {
            margin-top: 10px;
            text-align: right;
        }
        .total-row {
            margin: 5px 0;
        }
        .total-label {
            display: inline-block;
            width: 120px;
            text-align: left;
        }
        .total-value {
            display: inline-block;
            width: 100px;
            text-align: right;
            font-weight: bold;
        }
        .amount-in-words {
            margin: 15px 0;
            font-style: italic;
        }
        .footer {
            position: absolute;
            bottom: 10mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #333;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
        .signature-area {
            margin-top: 30px;
            margin-bottom: 60px;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="company-name">PHYTO AGHBALOU</h1>
            <h2 class="company-name-arabic">فيتو أغبالو</h2>
        </div>
        
        <div class="document-title">BON FACTURE</div>
        
        <div class="info-grid">
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Reference de facture</span>
                    <span>: <?php echo htmlspecialchars($facture['Numero_Facture']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date de facture</span>
                    <span>: <?php echo date('Y-m-d', strtotime($facture['Date_Facture'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Statut règlement</span>
                    <span>: <?php echo $facture['Statut_Facture'] === 'Payée' ? 'Réglé' : 'Non réglé'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mode de paiement</span>
                    <span>: <?php echo htmlspecialchars($facture['Mode_Paiement'] ?? ''); ?></span>
                </div>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Code client</span>
                    <span>: <?php echo htmlspecialchars($facture['client_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Client</span>
                    <span>: <?php echo htmlspecialchars($facture['Nom_Client']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Adresse</span>
                    <span>: <?php echo htmlspecialchars($facture['adresse'] ?? 'Aghbalou'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Téléphone</span>
                    <span>: <?php echo htmlspecialchars($facture['telephone'] ?? ''); ?></span>
                </div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th class="text-center">Quantité</th>
                    <th class="text-right">Prix TTC</th>
                    <th class="text-right">Montant TTC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facture_details as $detail): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($detail['designation'] ?? 'produit'); ?></td>
                        <td class="text-center"><?php echo $detail['quantite']; ?></td>
                        <td class="text-right"><?php echo number_format($detail['prix_unitaire'], 1); ?></td>
                        <td class="text-right"><?php echo number_format($detail['montant_ht'], 1); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="amount-in-words">
            <strong>Arrêtée la présente facture à la somme de :</strong>
            <div><?php echo $montant_en_lettres; ?></div>
        </div>
        
        <div class="totals">
            <div class="total-row">
                <span class="total-label">Total Hors Taxe :</span>
                <span class="total-value"><?php echo number_format($total_ht, 1); ?> DH</span>
            </div>
            <div class="total-row">
                <span class="total-label">Total Remise :</span>
                <span class="total-value"><?php echo number_format($remise, 1); ?> DH</span>
            </div>
            <div class="total-row">
                <span class="total-label">TVA :</span>
                <span class="total-value"><?php echo number_format($total_tva, 1); ?> DH</span>
            </div>
            <div class="total-row" style="font-size: 14pt;">
                <span class="total-label">Total :</span>
                <span class="total-value"><?php echo number_format($total_after_remise, 1); ?> DH</span>
            </div>
        </div>
        
        <div class="signature-area">
            <!-- Signature space -->
        </div>
        
        <div class="footer">
            <p>Sté Phytho Aghbalou. Adresse : boumia, Tél: 0666666666</p>
        </div>
        
        <div class="no-print" style="margin-top: 20px; text-align: center;">
            <button onclick="window.print();" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; font-size: 16px;">Imprimer</button>
            <button onclick="window.close();" style="padding: 10px 20px; background-color: #f44336; color: white; border: none; cursor: pointer; font-size: 16px; margin-left: 10px;">Fermer</button>
        </div>
    </div>
</body>
</html>
