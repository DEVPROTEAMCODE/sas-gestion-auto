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

// Calculer le nombre total d'articles
$nombre_articles = 0;
$somme_articles = 0;
foreach ($facture_details as $detail) {
    $nombre_articles += $detail['quantite'];
    $somme_articles++;
}

// Fonction pour convertir les montants en lettres
function numberToWords($number) {
    $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'];
    $tens = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante-dix', 'quatre-vingt', 'quatre-vingt-dix'];
    $teens = ['dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    
    if ($number == 0) {
        return 'zéro';
    }
    
    $words = '';
    
    // Milliards
    if ($number >= 1000000000) {
        $words .= numberToWords(floor($number / 1000000000)) . ' milliard';
        if (floor($number / 1000000000) > 1) $words .= 's';
        $number %= 1000000000;
        if ($number > 0) $words .= ' ';
    }
    
    // Millions
    if ($number >= 1000000) {
        $words .= numberToWords(floor($number / 1000000)) . ' million';
        if (floor($number / 1000000) > 1) $words .= 's';
        $number %= 1000000;
        if ($number > 0) $words .= ' ';
    }
    
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

// Convertir le montant total en lettres
$montant_en_lettres = numberToWords(floor($facture['Montant_Total_HT']));
if ($montant_en_lettres) {
    $montant_en_lettres = ucfirst($montant_en_lettres) . ' dhs';
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
            font-size: 12pt;
            line-height: 1.4;
        }
        .container {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 5mm;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .logo {
            font-size: 24pt;
            font-weight: bold;
        }
        .company-info {
            text-align: right;
            font-size: 10pt;
        }
        .document-title {
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            margin: 20px 0;
            text-transform: uppercase;
        }
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .client-info, .invoice-info {
            width: 48%;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .info-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
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
        .summary {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        .summary-left {
            width: 60%;
        }
        .summary-right {
            width: 35%;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .total-row {
            font-weight: bold;
            font-size: 14pt;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10pt;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .signature {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 45%;
            height: 80px;
            border: 1px solid #ddd;
            padding: 10px;
        }
        .signature-title {
            text-align: center;
            font-weight: bold;
            margin-bottom: 50px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">VOTRE ENTREPRISE</div>
            <div class="company-info">
                <p>ICE: 123456789000012</p>
                <p>Adresse: 68 Rue Zanka Dahabia, Taswat 1 - El Attaouia</p>
                <p>Tél: 06 24 70 39 49</p>
            </div>
        </div>
        
        <div class="document-title">BON DE LIVRAISON FACTURE</div>
        
        <div class="info-section">
            <div class="client-info">
                <div class="info-title">Client:</div>
                <p><?php echo htmlspecialchars($facture['Nom_Client']); ?></p>
                <p>Code client: <?php echo htmlspecialchars($facture['client_code'] ?? 'CL-' . $facture['client_id']); ?></p>
                <p>Adresse: <?php echo htmlspecialchars($facture['adresse'] ?? ''); ?></p>
                <p>Tél: <?php echo htmlspecialchars($facture['telephone'] ?? ''); ?></p>
            </div>
            <div class="invoice-info">
                <div class="info-title">Facture:</div>
                <p>N° de BL facture: <?php echo htmlspecialchars($facture['Numero_Facture']); ?></p>
                <p>Date de facture: <?php echo date('d/m/Y', strtotime($facture['Date_Facture'])); ?></p>
                <p>Date d'échéance: <?php echo date('d/m/Y', strtotime($facture['Date_Facture'] . ' +30 days')); ?></p>
                <p>Mode de paiement: <?php echo htmlspecialchars($facture['Mode_Paiement'] ?? 'ESPECE'); ?></p>
                <p>Vendeur: <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Vendeur'); ?></p>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Quantité</th>
                    <th>Désignation</th>
                    <th>Prix Unit. TTC</th>
                    <th>Nbr Pièces par Carton</th>
                    <th>Montant TTC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facture_details as $detail): ?>
                    <tr>
                        <td class="text-center"><?php echo $detail['quantite']; ?></td>
                        <td><?php echo htmlspecialchars($detail['designation']); ?></td>
                        <td class="text-right"><?php echo number_format($detail['prix_unitaire'], 2, ',', ' '); ?></td>
                        <td class="text-center">1</td>
                        <td class="text-right"><?php echo number_format($detail['montant_ht'], 2, ',', ' '); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="summary">
            <div class="summary-left">
                <p>Nombre d'articles: <?php echo $somme_articles; ?></p>
                <p>Somme d'articles: <?php echo $nombre_articles; ?></p>
                <p>Arrêtée la présente facture à la somme de:</p>
                <p><strong><?php echo $montant_en_lettres; ?></strong></p>
            </div>
            <div class="summary-right">
                <table style="width: 100%; border: none;">
                    <tr>
                        <td>Total TTC:</td>
                        <td class="text-right"><?php echo number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> Dhs</td>
                    </tr>
                    <tr>
                        <td>Paiements:</td>
                        <td class="text-right"><?php echo $facture['Statut_Facture'] === 'Payée' ? number_format($facture['Montant_Total_HT'], 2, ',', ' ') : '0,00'; ?> Dhs</td>
                    </tr>
                    <tr class="total-row">
                        <td>Reste à payer:</td>
                        <td class="text-right"><?php echo $facture['Statut_Facture'] === 'Payée' ? '0,00' : number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> Dhs</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total reste:</td>
                        <td class="text-right"><?php echo $facture['Statut_Facture'] === 'Payée' ? '0,00' : number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> Dhs</td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="signature">
            <div class="signature-box">
                <div class="signature-title">Signature Client</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">Signature Vendeur</div>
            </div>
        </div>
        
        <div class="footer">
            <p>Tél: 06 24 70 39 49 - ﺔﻳﻭﺎﻄﻌﻟﺍ - ﺔﻴﺒﻳﺎﻫﺬﻟﺍ ﺔﻘﻧﺯ 68 ﻢﻗﺭ 1 ﺕﻭﺎﺴﺗ ﺔﺋﺰﺠﺗ</p>
        </div>
        
        <div class="no-print" style="margin-top: 20px; text-align: center;">
            <button id="printButton" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; font-size: 16px;">Imprimer</button>
            <button onclick="window.close();" style="padding: 10px 20px; background-color: #f44336; color: white; border: none; cursor: pointer; font-size: 16px; margin-left: 10px;">Fermer</button>
        </div>
    </div>
    
    <script>
        // Set the document title with invoice number
        document.title = "<?php echo $pageTitle; ?>";
        
        // Handle print button click
        document.getElementById('printButton').addEventListener('click', function() {
            window.print();
        });
        
        // Auto-print when page loads if requested via URL parameter
        window.onload = function() {
            // Check if auto-print parameter is set
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('print') && urlParams.get('print') === 'true') {
                setTimeout(function() {
                    window.print();
                }, 500); // Small delay to ensure everything is loaded
            }
        };
    </script>
</body>
</html>
