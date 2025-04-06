<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    // Pour le développement, créer un utilisateur factice
    $_SESSION['user_id'] = 1;
}

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Récupérer la liste des véhicules et techniciens pour le formulaire
$database = new Database();
$db = $database->getConnection();

// Vérifier si un ID est passé dans l'URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id_commande = $_GET['id'];
$commande = null;
$commande_details = [];

// Récupérer les informations de la commande
try {
    $query = "SELECT c.*, cl.id as client_id,
                     CASE 
                        WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
                        ELSE CONCAT(cl.nom, ' - ', cl.raison_sociale)
                     END AS Nom_Client,
                     cl.adresse, cl.telephone, cl.email
              FROM commandes c
              LEFT JOIN clients cl ON c.ID_Client = cl.id
              WHERE c.ID_Commande = :id_commande";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_commande', $id_commande);
    $stmt->execute();
    $commande = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$commande) {
        header('Location: index.php');
        exit;
    }

    // Récupérer les détails de la commande
    $query = "SELECT cd.*, a.designation, a.reference 
              FROM commande_details cd
              LEFT JOIN articles a ON cd.article_id = a.id
              WHERE cd.ID_Commande = :id_commande";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id_commande', $id_commande);
    $stmt->execute();
    $commande_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// Fonction pour formater les dates
function formatDateFr($date) {
    if (!$date) return '';
    $timestamp = strtotime($date);
    return date('d/m/Y', $timestamp);
}

// Fonction pour formater les montants
function formatMontant($montant) {
    return number_format($montant, 2, ',', ' ') . ' DH';
}

// Déterminer le statut de la commande pour l'affichage
$statusClass = '';
$statusBadge = '';

switch ($commande['Statut_Commande']) {
    case 'En attente':
        $statusClass = 'bg-yellow-100 text-yellow-800';
        $statusBadge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">En attente</span>';
        break;
    case 'Confirmée':
        $statusClass = 'bg-blue-100 text-blue-800';
        $statusBadge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Confirmée</span>';
        break;
    case 'Livrée':
        $statusClass = 'bg-green-100 text-green-800';
        $statusBadge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Livrée</span>';
        break;
    case 'Annulée':
        $statusClass = 'bg-red-100 text-red-800';
        $statusBadge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Annulée</span>';
        break;
    default:
        $statusClass = 'bg-gray-100 text-gray-800';
        $statusBadge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">' . $commande['Statut_Commande'] . '</span>';
}

include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto">
        <header class="bg-white shadow-sm sticky top-0 z-10">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                <h1 class="text-2xl font-bold text-gray-900">Détails de la commande</h1>
                <div class="flex space-x-3">
                    <button id="print-btn" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Imprimer
                    </button>
                    <a href="edit.php?id=<?php echo $id_commande; ?>" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                        <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Modifier
                    </a>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                        <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12" />
                        </svg>
                        Retour
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <?php if (isset($error_message)): ?>
                <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-md shadow-sm" role="alert">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium"><?php echo $error_message; ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Command Details -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg mb-6">
                <div class="px-4 py-5 sm:px-6 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Commande #<?php echo htmlspecialchars($commande['Numero_Commande']); ?></h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-500">Détails de la commande et informations client</p>
                    </div>
                    <div>
                        <?php echo $statusBadge; ?>
                    </div>
                </div>
                <div class="border-t border-gray-200">
                    <dl>
                        <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                            <dt class="text-sm font-medium text-gray-500">Numéro de commande</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2"><?php echo htmlspecialchars($commande['Numero_Commande']); ?></dd>
                        </div>
                        <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                            <dt class="text-sm font-medium text-gray-500">Date de commande</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2"><?php echo formatDateFr($commande['Date_Commande']); ?></dd>
                        </div>
                        <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                            <dt class="text-sm font-medium text-gray-500">Date de livraison prévue</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2"><?php echo formatDateFr($commande['Date_Livraison_Prevue']); ?></dd>
                        </div>
                        <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                            <dt class="text-sm font-medium text-gray-500">Client</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                                <div class="font-medium"><?php echo htmlspecialchars($commande['Nom_Client']); ?></div>
                                <?php if (!empty($commande['adresse'])): ?>
                                    <div class="text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($commande['adresse'])); ?></div>
                                <?php endif; ?>
                                <div class="flex flex-wrap gap-x-4 mt-1">
                                    <?php if (!empty($commande['telephone'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <svg class="h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                            </svg>
                                            <?php echo htmlspecialchars($commande['telephone']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($commande['email'])): ?>
                                        <div class="flex items-center text-gray-600">
                                            <svg class="h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                            <?php echo htmlspecialchars($commande['email']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </dd>
                        </div>
                        <div class="bg-gray-50 px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                            <dt class="text-sm font-medium text-gray-500">Montant total HT</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2 font-semibold"><?php echo formatMontant($commande['Montant_Total_HT']); ?></dd>
                        </div>
                        <?php if (!empty($commande['Notes'])): ?>
                            <div class="bg-white px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                                <dt class="text-sm font-medium text-gray-500">Notes</dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2"><?php echo nl2br(htmlspecialchars($commande['Notes'])); ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Articles List -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <div class="px-4 py-5 sm:px-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">Articles commandés</h3>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">Liste des articles dans cette commande</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Référence</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Désignation</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantité</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Prix unitaire</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total HT</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($commande_details)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">Aucun article dans cette commande</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($commande_details as $detail): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($detail['reference']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($detail['designation']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?php echo htmlspecialchars($detail['quantite']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?php echo formatMontant($detail['prix_unitaire']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium"><?php echo formatMontant($detail['montant_ht']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Total Row -->
                                <tr class="bg-gray-50">
                                    <td colspan="4" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">Total HT:</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right"><?php echo formatMontant($commande['Montant_Total_HT']); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 flex justify-end space-x-3">
                <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                    Retour à la liste
                </a>
               
</div>

<!-- Print Template -->
<div id="print-template" class="hidden">
    <div class="print-content p-8">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold">Bon de Commande</h1>
            <p class="text-lg mt-2">N° <?php echo htmlspecialchars($commande['Numero_Commande']); ?></p>
        </div>
        
        <div class="flex justify-between mb-8">
            <div>
                <h2 class="font-bold text-lg">Client:</h2>
                <p class="mt-1"><?php echo htmlspecialchars($commande['Nom_Client']); ?></p>
                <?php if (!empty($commande['adresse'])): ?>
                    <p class="text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($commande['adresse'])); ?></p>
                <?php endif; ?>
                <?php if (!empty($commande['telephone'])): ?>
                    <p class="text-gray-600 mt-1">Tél: <?php echo htmlspecialchars($commande['telephone']); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <div class="mb-2">
                    <span class="font-semibold">Date de commande:</span>
                    <span class="ml-2"><?php echo formatDateFr($commande['Date_Commande']); ?></span>
                </div>
                <div>
                    <span class="font-semibold">Date de livraison prévue:</span>
                    <span class="ml-2"><?php echo formatDateFr($commande['Date_Livraison_Prevue']); ?></span>
                </div>
                <div class="mt-2">
                    <span class="font-semibold">Statut:</span>
                    <span class="ml-2"><?php echo htmlspecialchars($commande['Statut_Commande']); ?></span>
                </div>
            </div>
        </div>
        
        <table class="w-full mb-8 border-collapse">
            <thead>
                <tr class="bg-gray-200">
                    <th class="border border-gray-400 p-2 text-left">Référence</th>
                    <th class="border border-gray-400 p-2 text-left">Désignation</th>
                    <th class="border border-gray-400 p-2 text-right">Quantité</th>
                    <th class="border border-gray-400 p-2 text-right">Prix unitaire</th>
                    <th class="border border-gray-400 p-2 text-right">Total HT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($commande_details as $detail): ?>
                    <tr>
                        <td class="border border-gray-400 p-2"><?php echo htmlspecialchars($detail['reference']); ?></td>
                        <td class="border border-gray-400 p-2"><?php echo htmlspecialchars($detail['designation']); ?></td>
                        <td class="border border-gray-400 p-2 text-right"><?php echo htmlspecialchars($detail['quantite']); ?></td>
                        <td class="border border-gray-400 p-2 text-right"><?php echo formatMontant($detail['prix_unitaire']); ?></td>
                        <td class="border border-gray-400 p-2 text-right"><?php echo formatMontant($detail['montant_ht']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="border border-gray-400 p-2 text-right font-bold">Total HT:</td>
                    <td class="border border-gray-400 p-2 text-right"><?php echo formatMontant($commande['Montant_Total_HT']); ?></td>
                </tr>
            </tfoot>
        </table>
        
        <?php if (!empty($commande['Notes'])): ?>
            <div class="mt-8">
                <h3 class="font-bold mb-2">Notes:</h3>
                <p class="border p-3 min-h-[60px] bg-gray-50"><?php echo nl2br(htmlspecialchars($commande['Notes'])); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="mt-12 flex justify-between">
            <div>
                <p class="font-bold mb-2">Signature du responsable:</p>
                <div class="h-16 w-48 border-b border-gray-400 mt-12"></div>
            </div>
            <div>
                <p class="font-bold mb-2">Signature du client:</p>
                <div class="h-16 w-48 border-b border-gray-400 mt-12"></div>
            </div>
        </div>
        
        <div class="text-center text-sm text-gray-500 mt-8">
            <p>Document généré le <?php echo date('d/m/Y à H:i'); ?></p>
        </div>
    </div>
</div>

<script>
    // Function to prepare and print the command
    function printCommand() {
        // Open print dialog
        const printContent = document.getElementById('print-template').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Bon de Commande - <?php echo htmlspecialchars($commande['Numero_Commande']); ?></title>
                <meta charset="UTF-8">
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        margin: 0; 
                        padding: 20px;
                        color: #333;
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        margin-bottom: 20px; 
                    }
                    th, td { 
                        border: 1px solid #ddd; 
                        padding: 8px; 
                    }
                    th { 
                        background-color: #f2f2f2; 
                        text-align: left; 
                    }
                    .text-right { 
                        text-align: right; 
                    }
                    .text-center { 
                        text-align: center; 
                    }
                    .font-bold { 
                        font-weight: bold; 
                    }
                    .header {
                        text-align: center;
                        margin-bottom: 20px;
                        border-bottom: 2px solid #333;
                        padding-bottom: 10px;
                    }
                    .header h1 {
                        margin-bottom: 5px;
                    }
                    .footer {
                        margin-top: 30px;
                        text-align: center;
                        font-size: 12px;
                        color: #666;
                    }
                    @media print {
                        body { 
                            margin: 0; 
                            padding: 15px; 
                        }
                        button { 
                            display: none; 
                        }
                        @page {
                            size: A4;
                            margin: 1cm;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>Bon de Commande</h1>
                    <p>N° <?php echo htmlspecialchars($commande['Numero_Commande']); ?></p>
                </div>
                ${printContent}
                <div class="footer">
                    <p>Document généré le <?php echo date('d/m/Y à H:i'); ?></p>
                </div>
                <div class="text-center" style="margin-top: 20px;">
                    <button onclick="window.print(); setTimeout(function() { window.close(); }, 500);" 
                            style="padding: 10px 20px; background-color: #4f46e5; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Imprimer
                    </button>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    // Initialize event listeners when the DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Add click event for the print button
        document.getElementById('print-btn').addEventListener('click', function(e) {
            e.preventDefault();
            printCommand();
        });
    });
</script>

<?php include $root_path . '/includes/footer.php'; ?>
