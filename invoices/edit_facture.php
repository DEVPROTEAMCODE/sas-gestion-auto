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
$errors = [];
$success_message = '';

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
                     cl.adresse, cl.ville, cl.code_postal, cl.telephone, cl.email
              FROM commandes c
              LEFT JOIN clients cl ON c.ID_Client = cl.id
              WHERE c.ID_Commande = :id_commande";

$commandeClientStmt = $db->prepare($commandeClientQuery);
$commandeClientStmt->bindParam(':id_commande', $facture['ID_Commande']);
$commandeClientStmt->execute();
$commandeClient = $commandeClientStmt->fetch(PDO::FETCH_ASSOC);

// Traitement du formulaire de mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    $numero_facture = trim($_POST['numero_facture']);
    $date_facture = trim($_POST['date_facture']);
    $statut_facture = trim($_POST['statut_facture']);
    $notes = trim($_POST['notes']);
    
    // Vérifier si le numéro de facture est unique (sauf pour la facture actuelle)
    $checkQuery = "SELECT COUNT(*) FROM factures WHERE Numero_Facture = :numero AND id != :id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':numero', $numero_facture);
    $checkStmt->bindParam(':id', $id_facture);
    $checkStmt->execute();
    
    if ($checkStmt->fetchColumn() > 0) {
        $errors[] = "Ce numéro de facture existe déjà.";
    }
    
    // Informations de paiement
    $date_paiement = !empty($_POST['date_paiement']) ? trim($_POST['date_paiement']) : null;
    $mode_paiement = !empty($_POST['mode_paiement']) ? trim($_POST['mode_paiement']) : null;
    $reference_paiement = !empty($_POST['reference_paiement']) ? trim($_POST['reference_paiement']) : null;
    
    // Si des informations de paiement sont fournies et que le statut n'est pas déjà "Payée", 
    // changer automatiquement le statut vers "Payée"
    if (!empty($mode_paiement) && !empty($date_paiement) && $statut_facture !== 'Payée') {
        $statut_facture = 'Payée';
    }
    
    // Si le statut est "Payée", vérifier que les informations de paiement sont complètes
    if ($statut_facture === 'Payée') {
        if (empty($mode_paiement)) {
            $errors[] = "Le mode de paiement est requis pour une facture payée.";
        }
        
        if (empty($date_paiement)) {
            $date_paiement = date('Y-m-d'); // Date du jour par défaut
        }
    }
    
    // Si pas d'erreurs, mettre à jour la facture
    if (empty($errors)) {
        $updateQuery = "UPDATE factures SET 
                        Numero_Facture = :numero_facture,
                        Date_Facture = :date_facture,
                        Statut_Facture = :statut_facture,
                        Date_Paiement = :date_paiement,
                        Mode_Paiement = :mode_paiement,
                        Reference_Paiement = :reference_paiement,
                        Notes = :notes,
                        Updated_At = NOW()
                        WHERE id = :id";
                        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':numero_facture', $numero_facture);
        $updateStmt->bindParam(':date_facture', $date_facture);
        $updateStmt->bindParam(':statut_facture', $statut_facture);
        $updateStmt->bindParam(':date_paiement', $date_paiement);
        $updateStmt->bindParam(':mode_paiement', $mode_paiement);
        $updateStmt->bindParam(':reference_paiement', $reference_paiement);
        $updateStmt->bindParam(':notes', $notes);
        $updateStmt->bindParam(':id', $id_facture);
        
        if ($updateStmt->execute()) {
            $success_message = "La facture a été mise à jour avec succès.";
            
            // Rafraîchir les données de la facture
            $factureStmt->execute();
            $facture = $factureStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $errors[] = "Une erreur s'est produite lors de la mise à jour de la facture.";
        }
    }
}

// Liste des statuts de facture possibles
$statuts_facture = ['En attente', 'Validée', 'Payée', 'En retard', 'Annulée'];

// Liste des modes de paiement
$modes_paiement = ['Espèces', 'Carte bancaire', 'Virement', 'Chèque', 'PayPal', 'Autre'];

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
                <h1 class="text-2xl font-bold text-gray-800">Modifier la Facture</h1>
                <a href="view_facture.php?id=<?php echo $id_facture; ?>" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    Retour
                </a>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Erreur!</strong>
                    <ul class="mt-1 list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Succès!</strong>
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="edit-facture-form">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Informations de la facture -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-700 mb-3">Informations de la Facture</h2>
                        
                        <div class="mb-4">
                            <label for="numero_facture" class="block text-sm font-medium text-gray-700 mb-1">Numéro de Facture</label>
                            <input type="text" id="numero_facture" name="numero_facture" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($facture['Numero_Facture']); ?>" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="date_facture" class="block text-sm font-medium text-gray-700 mb-1">Date de Facture</label>
                            <input type="date" id="date_facture" name="date_facture" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo $facture['Date_Facture']; ?>" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="statut_facture" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                            <select id="statut_facture" name="statut_facture" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <?php foreach ($statuts_facture as $statut): ?>
                                    <option value="<?php echo $statut; ?>" <?php echo ($facture['Statut_Facture'] === $statut) ? 'selected' : ''; ?>>
                                        <?php echo $statut; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($facture['Notes']); ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Informations du client (lecture seule) -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-700 mb-3">Informations du Client</h2>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                            <div class="px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                                <?php echo htmlspecialchars($commandeClient['Nom_Client']); ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                            <div class="px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                                <?php echo htmlspecialchars($commandeClient['telephone']); ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <div class="px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                                <?php echo htmlspecialchars($commandeClient['email']); ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                            <div class="px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-700">
                                <?php echo htmlspecialchars($commandeClient['adresse']); ?><br>
                                <?php echo htmlspecialchars($commandeClient['code_postal'] . ' ' . $commandeClient['ville']); ?><br>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Section de paiement (visible uniquement si le statut est "Validée" ou "Payée") -->
                <div id="payment-section" class="bg-gray-50 p-4 rounded-lg mb-6 <?php echo ($facture['Statut_Facture'] === 'Validée' || $facture['Statut_Facture'] === 'Payée') ? '' : 'hidden'; ?>">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">Informations de Paiement</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label for="date_paiement" class="block text-sm font-medium text-gray-700 mb-1">Date de Paiement</label>
                            <input type="date" id="date_paiement" name="date_paiement" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo !empty($facture['Date_Paiement']) ? $facture['Date_Paiement'] : date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-4">
                            <label for="mode_paiement" class="block text-sm font-medium text-gray-700 mb-1">Mode de Paiement</label>
                            <select id="mode_paiement" name="mode_paiement" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Sélectionner --</option>
                                <?php foreach ($modes_paiement as $mode): ?>
                                    <option value="<?php echo $mode; ?>" <?php echo ($facture['Mode_Paiement'] === $mode) ? 'selected' : ''; ?>>
                                        <?php echo $mode; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4 md:col-span-2">
                            <label for="reference_paiement" class="block text-sm font-medium text-gray-700 mb-1">Référence de Paiement</label>
                            <input type="text" id="reference_paiement" name="reference_paiement" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($facture['Reference_Paiement'] ?? ''); ?>" placeholder="Numéro de chèque, référence de transaction, etc.">
                        </div>
                    </div>
                </div>
                
                <!-- Résumé financier (lecture seule) -->
                <div class="bg-gray-50 p-4 rounded-lg mb-6">
                    <h2 class="text-lg font-semibold text-gray-700 mb-3">Résumé Financier</h2>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <div class="text-sm font-bold text-gray-700">Total HT:</div>
                        <div class="text-sm text-gray-900 text-right">
                            <?php echo number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> DH
                        </div>
                      
                    </div>
                </div>
                
                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-2">
                    <a href="view_facture.php?id=<?php echo $id_facture; ?>" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Annuler
                    </a>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const statutSelect = document.getElementById('statut_facture');
    const paymentSection = document.getElementById('payment-section');
    const modePaiementSelect = document.getElementById('mode_paiement');
    const datePaiementInput = document.getElementById('date_paiement');
    const editForm = document.getElementById('edit-facture-form');
    
    // Fonction pour gérer l'affichage de la section de paiement
    function handlePaymentSectionVisibility() {
        const selectedStatus = statutSelect.value;
        
        if (selectedStatus === 'Validée' || selectedStatus === 'Payée') {
            paymentSection.classList.remove('hidden');
            
            // Si le statut est "Payée", rendre les champs de paiement obligatoires
            if (selectedStatus === 'Payée') {
                modePaiementSelect.setAttribute('required', 'required');
                datePaiementInput.setAttribute('required', 'required');
            } else {
                modePaiementSelect.removeAttribute('required');
                datePaiementInput.removeAttribute('required');
            }
        } else {
            paymentSection.classList.add('hidden');
            modePaiementSelect.removeAttribute('required');
            datePaiementInput.removeAttribute('required');
        }
    }
    
    // Écouter les changements de statut
    statutSelect.addEventListener('change', handlePaymentSectionVisibility);
    
    // Vérifier avant la soumission si des informations de paiement sont fournies
    editForm.addEventListener('submit', function(e) {
        const currentStatus = statutSelect.value;
        const modeValue = modePaiementSelect.value;
        const dateValue = datePaiementInput.value;
        
        // Si des informations de paiement sont fournies et que le statut n'est pas déjà "Payée",
        // suggérer de changer le statut vers "Payée"
        if (currentStatus === 'Validée' && modeValue !== '' && dateValue !== '') {
            if (confirm('Des informations de paiement ont été saisies. Voulez-vous changer le statut de la facture à "Payée" ?')) {
                statutSelect.value = 'Payée';
            }
        }
    });
    
    // Déclencher l'événement au chargement pour initialiser l'état
    handlePaymentSectionVisibility();
});
</script>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>
