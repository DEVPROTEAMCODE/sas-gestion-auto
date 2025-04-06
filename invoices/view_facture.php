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
    header("Location: ../login.php");
    exit;
}

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Vérifier si l'ID de la facture est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id_facture = $_GET['id'];

$database = new Database();
$db = $database->getConnection();

// Récupérer les informations de la facture
$factureQuery = "SELECT f.* FROM factures f WHERE f.id = :id";
$factureStmt = $db->prepare($factureQuery);
$factureStmt->bindParam(':id', $id_facture);
$factureStmt->execute();

if ($factureStmt->rowCount() === 0) {
    header('Location: index.php');
    exit;
}

$facture = $factureStmt->fetch(PDO::FETCH_ASSOC);

// Récupérer les informations de la commande et du client associés
$commandeClientQuery = "SELECT c.*, cl.id as client_id,
                     CASE 
                        WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
                        ELSE CONCAT(cl.nom, ' - ', cl.raison_sociale)
                     END AS Nom_Client,
                     cl.adresse, cl.ville, cl.code_postal, cl.telephone, cl.email,
                     cl.type_client_id, cl.raison_sociale, cl.registre_rcc, cl.nom as client_nom, cl.prenom as client_prenom
              FROM commandes c
              LEFT JOIN clients cl ON c.ID_Client = cl.id
              WHERE c.ID_Commande = :id_commande";

$commandeClientStmt = $db->prepare($commandeClientQuery);
$commandeClientStmt->bindParam(':id_commande', $facture['ID_Commande']);
$commandeClientStmt->execute();
$commandeClient = $commandeClientStmt->fetch(PDO::FETCH_ASSOC);

// Récupérer les articles de la facture en utilisant la jointure
$articlesQuery = "SELECT 
                    f.id AS facture_id,
                    f.Numero_Facture,
                    f.Date_Facture,
                    c.Numero_Commande,
                    a.id AS article_id,
                    a.reference AS reference_article,
                    a.designation AS nom_article,
                    cd.quantite,
                    cd.prix_unitaire,
                    cd.montant_ht,
                    cd.remise
                FROM 
                    factures f
                INNER JOIN 
                    commandes c ON f.ID_Commande = c.ID_Commande
                INNER JOIN 
                    commande_details cd ON c.ID_Commande = cd.ID_Commande
                INNER JOIN 
                    articles a ON cd.article_id = a.id
                WHERE 
                    f.id = :id_facture";

$articlesStmt = $db->prepare($articlesQuery);
$articlesStmt->bindParam(':id_facture', $id_facture);
$articlesStmt->execute();
$articles = $articlesStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculer le montant total TTC (avec TVA à 20%)
$tva_rate = 0.20; // 20%
$montant_tva = $facture['Montant_Total_HT'] * $tva_rate;
$montant_ttc = $facture['Montant_Total_HT'] + $montant_tva;

// Inclure l'en-tête
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto p-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Détails de la Facture</h1>
                <div class="flex space-x-2">
                    <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Retour
                    </a>
                    <a href="edit_facture.php?id=<?php echo $id_facture; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Modifier
                    </a>
                    <a href="print_facture.php?id=<?php echo $id_facture; ?>" target="_blank" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        Imprimer
                    </a>
                    <a href="send_invoice_email.php?id=<?php echo $id_facture; ?>" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" onclick="return confirm('Envoyer cette facture par email au client?');">
                        Envoyer par Email
                    </a>
                </div>
            </div>
            
            <!-- Informations de la facture -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">Informations de la Facture</h2>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="text-sm font-medium text-gray-500">Numéro de Facture:</div>
                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($facture['Numero_Facture']); ?></div>
                        
                        <div class="text-sm font-medium text-gray-500">Date de Facture:</div>
                        <div class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($facture['Date_Facture'])); ?></div>
                        
                        <div class="text-sm font-medium text-gray-500">Statut:</div>
                        <div class="text-sm">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                switch($facture['Statut_Facture']) {
                                    case 'Payée':
                                        echo 'bg-green-100 text-green-800';
                                        break;
                                    case 'En attente':
                                        echo 'bg-yellow-100 text-yellow-800';
                                        break;
                                    case 'En retard':
                                        echo 'bg-red-100 text-red-800';
                                        break;
                                    case 'Annulée':
                                        echo 'bg-gray-100 text-gray-800';
                                        break;
                                    case 'Validée':
                                        echo 'bg-indigo-100 text-indigo-800';
                                        break;
                                    default:
                                        echo 'bg-blue-100 text-blue-800';
                                }
                                ?>">
                                <?php echo htmlspecialchars($facture['Statut_Facture']); ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($facture['Date_Paiement'])): ?>
                            <div class="text-sm font-medium text-gray-500">Date de Paiement:</div>
                            <div class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($facture['Date_Paiement'])); ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($facture['Mode_Paiement'])): ?>
                            <div class="text-sm font-medium text-gray-500">Mode de Paiement:</div>
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($facture['Mode_Paiement']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($facture['Reference_Paiement'])): ?>
                            <div class="text-sm font-medium text-gray-500">Référence de Paiement:</div>
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($facture['Reference_Paiement']); ?></div>
                        <?php endif; ?>
                        
                        <div class="text-sm font-medium text-gray-500">Numéro de Commande:</div>
<div class="text-sm text-gray-900">
    <?php if (!empty($commandeClient['Numero_Commande'])): ?>
        <a href="../orders/view.php?id=<?php echo $facture['ID_Commande']; ?>" class="text-blue-600 hover:text-blue-800 hover:underline">
            <?php echo htmlspecialchars($commandeClient['Numero_Commande']); ?>
        </a>
    <?php else: ?>
        N/A
    <?php endif; ?>
</div>

                </div>
                </div>
                
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">Informations du Client</h2>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="text-sm font-medium text-gray-500">Client:</div>
                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($commandeClient['Nom_Client']); ?></div>
                        
                        <?php if ($commandeClient['type_client_id'] != 1 && !empty($commandeClient['registre_rcc'])): ?>
                            <div class="text-sm font-medium text-gray-500">Registre commerce:</div>
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($commandeClient['registre_rcc']); ?></div>
                        <?php endif; ?>
                        
                        <div class="text-sm font-medium text-gray-500">Téléphone:</div>
                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($commandeClient['telephone']); ?></div>
                        
                        <div class="text-sm font-medium text-gray-500">Email:</div>
                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($commandeClient['email']); ?></div>
                        
                        <div class="text-sm font-medium text-gray-500">Adresse:</div>
                        <div class="text-sm text-gray-900">
                            <?php echo htmlspecialchars($commandeClient['adresse']); ?><br>
                            <?php echo htmlspecialchars($commandeClient['code_postal'] . ' ' . $commandeClient['ville']); ?><br>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Articles de la facture -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-700 mb-3">Articles</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Référence</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Désignation</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantité</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Prix Unitaire HT</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Remise</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Montant HT</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($articles) > 0): ?>
                                <?php foreach ($articles as $article): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($article['reference_article']); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php echo htmlspecialchars($article['nom_article']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                            <?php echo htmlspecialchars($article['quantite']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                            <?php echo number_format($article['prix_unitaire'], 2, ',', ' '); ?> DH
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                            <?php echo !empty($article['remise']) ? number_format($article['remise'], 2, ',', ' ') . ' %' : '0,00 %'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                            <?php echo number_format($article['montant_ht'], 2, ',', ' '); ?> DH
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                        Aucun article trouvé pour cette facture.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Résumé financier -->
            <div class="flex justify-end mb-6">
                <div class="w-full md:w-1/3">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="text-sm font-medium text-gray-500">Total:</div>
                            <div class="text-sm text-gray-900 text-right"><?php echo number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> DH</div>
                            
                           
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notes -->
            <?php if (!empty($facture['Notes'])): ?>
                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">Notes</h2>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($facture['Notes'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="flex justify-between items-center mt-8">
                <div>
                    <p class="text-xs text-gray-500">
                        Créée le: <?php echo date('d/m/Y H:i', strtotime($facture['Created_At'])); ?>
                        <?php if (!empty($facture['Updated_At'])): ?>
                            | Dernière mise à jour: <?php echo date('d/m/Y H:i', strtotime($facture['Updated_At'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="flex space-x-2">
                    <?php if ($facture['Statut_Facture'] === 'En attente'): ?>
                        <a href="index.php?validate=<?php echo $id_facture; ?>" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" onclick="return confirm('Valider cette facture?');">
                            Valider la Facture
                        </a>
                    <?php elseif ($facture['Statut_Facture'] === 'Validée'): ?>
                        <a href="index.php?mark_paid=<?php echo $id_facture; ?>" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500" onclick="return confirm('Marquer cette facture comme payée?');">
                            Marquer comme Payée
                        </a>
                    <?php endif; ?>
                    <a href="index.php?delete=<?php echo $id_facture; ?>" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette facture? Cette action est irréversible.');">
                        Supprimer
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>
