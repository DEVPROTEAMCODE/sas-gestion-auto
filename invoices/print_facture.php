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

// Récupérer les informations de la société
$query_company = "SELECT * FROM societe LIMIT 1";
$stmt_company = $db->prepare($query_company);
$stmt_company->execute();
$company_info = $stmt_company->fetch(PDO::FETCH_ASSOC);

// Récupérer les informations de la facture avec les données client
$query = "SELECT f.*, cl.id as client_id ,
          CASE 
             WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
             ELSE CONCAT( cl.raison_sociale)
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

// Calculer le nombre total d'articles et la somme des quantités
$nombre_articles = count($facture_details);
$somme_articles = 0;
foreach ($facture_details as $detail) {
    $somme_articles += $detail['quantite'];
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
$total_ttc = $facture['Montant_Total_TTC'] ?? $facture['Montant_Total_HT'] ?? 0;
$remise = $facture['Remise'] ?? 0;
$total_after_remise = $total_ttc - $remise;

// Convertir le montant total en lettres
$montant_en_lettres = numberToWords(floor($total_after_remise));
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
            position: relative;
            min-height: 277mm; /* A4 height minus margins */
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .logo-container {
            display: flex;
            align-items: center;
        }
        .logo {
            max-height: 70px;
            margin-right: 15px;
        }
        .company-name {
            font-size: 22pt;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 0;
        }
        .document-title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
            margin: 20px 0;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-box {
            border: 1.5px solid #ccc;
            padding: 12px;
            background-color: #f9f9f9;
            border-radius: 5px;
        }
        .info-row {
            margin-bottom: 8px;
        }
        .info-label {
            font-weight: bold;
            color: #333;
            display: inline-block;
            width: 130px;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table.items th, table.items td {
            border: 1.5px solid #ddd;
            padding: 8px;
        }
        table.items th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .summary-section {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
        }
        .amount-in-words {
            width: 60%;
        }
        .totals-container {
            width: 38%;
        }
        .totals {
            border: 1.5px solid #ddd;
            padding: 12px;
            border-radius: 5px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .total-label {
            font-weight: bold;
        }
        .total-value {
            text-align: right;
            min-width: 120px;
        }
        .bold {
            font-weight: bold;
        }
        .footer {
            position: absolute;
            bottom: 10mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10pt;
            color: #333;
            border-top: 1px solid #ddd;
            padding-top: 8px;
        }
        @media print {
            .no-print {
                display: none;
            }
            a {
                text-decoration: none;
                color: inherit;
            }
        }
        /* Remove URL display in print mode */
        @media print {
            a:after {
                content: "" !important;
            }
            a[href]:after {
                content: "" !important;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-container">
                <?php if (!empty($company_info['logo'])): ?>
                    <img src="<?php echo '../' . $company_info['logo']; ?>" alt="Logo" class="logo">
                <?php endif; ?>
                <div>
                    <div class="company-name"><?php echo htmlspecialchars($company_info['raison_sociale'] ?? 'VOTRE ENTREPRISE'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="document-title">BON DE LIVRAISON FACTURE</div>
        
        <div class="info-grid">
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">N° BL facture :</span>
                    <span><?php echo htmlspecialchars($facture['Numero_Facture']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date de facture :</span>
                    <span><?php echo date('d/m/Y', strtotime($facture['Date_Facture'])); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Mode de paiement :</span>
                    <span><?php echo htmlspecialchars($facture['Mode_Paiement'] ); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Vendeur :</span>
                    <span><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Vendeur'); ?></span>
                </div>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Code client :</span>
                    <span><?php echo htmlspecialchars($facture['client_code'] ?? 'CL-' . $facture['client_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Client :</span>
                    <span><?php echo htmlspecialchars($facture['Nom_Client']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Adresse :</span>
                    <span><?php echo htmlspecialchars($facture['adresse'] ?? ''); ?></span>
                </div>
               
                <div class="info-row">
                    <span class="info-label">Téléphone :</span>
                    <span><?php echo htmlspecialchars($facture['telephone'] ?? ''); ?></span>
                </div>
            </div>
        </div>
        
        <table class="items">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th>Quantité</th>
                    <th>Prix Unit. TTC</th>
                    <th>Montant TTC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facture_details as $detail): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($detail['designation'] ?? ''); ?></td>
                        <td class="text-center"><?php echo $detail['quantite']; ?></td>
                        <td class="text-right"><?php echo number_format($detail['prix_unitaire'], 2, ',', ' '); ?></td>
                        <td class="text-right"><?php echo number_format($detail['montant_ht'], 2, ',', ' '); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="summary-section">
            <div class="amount-in-words">
                <div class="info-row">
                    <span>Nombre d'articles : <?php echo $nombre_articles; ?></span>
                </div>
                <div class="info-row">
                    <span>Somme d'articles : <?php echo $somme_articles; ?></span>
                </div>
                <div class="info-row">
                    <span><strong>Arrêtée la présente facture à la somme de :</strong></span>
                </div>
                <div class="info-row">
                    <span><strong><?php echo $montant_en_lettres; ?></strong></span>
                </div>
            </div>
            
            <div class="totals-container">
                <div class="totals">
                    <div class="total-row">
                        <span class="total-label">Total TTC :</span>
                        <span class="total-value"><?php echo number_format($total_ttc, 2, ',', ' '); ?> Dhs</span>
                    </div>
                    <div class="total-row">
                        <span class="total-label">Paiements :</span>
                        <span class="total-value"><?php echo $facture['Statut_Facture'] === 'Payée' ? number_format($total_ttc, 2, ',', ' ') : '0,00'; ?> Dhs</span>
                    </div>
                    <div class="total-row bold">
                        <span class="total-label">Reste à payer :</span>
                        <span class="total-value"><?php echo $facture['Statut_Facture'] === 'Payée' ? '0,00' : number_format($total_ttc, 2, ',', ' '); ?> Dhs</span>
                    </div>
                    <div class="total-row bold">
                        <span class="total-label">Total reste :</span>
                        <span class="total-value"><?php echo $facture['Statut_Facture'] === 'Payée' ? '0,00' : number_format($total_ttc, 2, ',', ' '); ?> Dhs</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>
                Tél : <?php echo htmlspecialchars($company_info['telephone_fixe'] ?? ''); ?> 
                <?php if (!empty($company_info['telephone_mobile'])): ?>
                    / <?php echo htmlspecialchars($company_info['telephone_mobile']); ?>
                <?php endif; ?> 
                - <?php echo htmlspecialchars($company_info['adresse'] ?? ''); ?>
            </p>
        </div>
        
        <div class="no-print" style="margin-top: 20px; text-align: center;">
            <button onclick="window.print();" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; font-size: 16px; border-radius: 4px;">Imprimer</button>
            <button onclick="window.close();" style="padding: 10px 20px; background-color: #f44336; color: white; border: none; cursor: pointer; font-size: 16px; margin-left: 10px; border-radius: 4px;">Fermer</button>
        </div>
    </div>
    
    <script>
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
        
        // Prevent URLs from showing in print mode
        window.addEventListener('beforeprint', function() {
            var links = document.getElementsByTagName('a');
            for (var i = 0; i < links.length; i++) {
                links[i].setAttribute('data-href', links[i].getAttribute('href'));
                links[i].removeAttribute('href');
            }
        });
        
        window.addEventListener('afterprint', function() {
            var links = document.getElementsByTagName('a');
            for (var i = 0; i < links.length; i++) {
                if (links[i].getAttribute('data-href')) {
                    links[i].setAttribute('href', links[i].getAttribute('data-href'));
                    links[i].removeAttribute('data-href');
                }
            }
        });
    </script>
</body>
</html>
