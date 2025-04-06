<?php
// Démarrer la session d'abord
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

// Récupérer les informations de l'utilisateur actuel
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT id, username, nom, prenom, role FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Si l'utilisateur n'existe pas, déconnecter
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }
} catch (PDOException $e) {
    // Log l'erreur et rediriger vers une page d'erreur
    error_log('Erreur de récupération des données utilisateur: ' . $e->getMessage());
    header('Location: ../error.php');
    exit;
}

// Assurer que $currentUser['name'] est défini pour éviter les erreurs
if (!isset($currentUser['name'])) {
    $currentUser['name'] = $currentUser['prenom'] . ' ' . $currentUser['nom'];
}
if (!isset($currentUser['role'])) {
    $currentUser['role'] = 'Utilisateur';
}

// Traitement de l'export
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$filtres = isset($_GET['filtres']) ? $_GET['filtres'] : 'tous';

// Filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$delai = isset($_GET['delai']) ? $_GET['delai'] : '';

// Construction de la requête avec filtres
$whereClause = [];
$params = [];

if (!empty($search)) {
    $whereClause[] = "(Code_Fournisseur LIKE :search OR Raison_Sociale LIKE :search OR Ville LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status !== '') {
    $whereClause[] = "Actif = :status";
    $params[':status'] = $status;
}

if (!empty($delai)) {
    switch ($delai) {
        case 'less5':
            $whereClause[] = "Delai_Livraison_Moyen < 5";
            break;
        case '5to10':
            $whereClause[] = "Delai_Livraison_Moyen BETWEEN 5 AND 10";
            break;
        case 'more10':
            $whereClause[] = "Delai_Livraison_Moyen > 10";
            break;
    }
}

$whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Si l'action d'export est demandée, générer le fichier
if (isset($_POST['export'])) {
    try {
        // Récupérer les données des fournisseurs
        $query = "SELECT * FROM fournisseurs $whereString ORDER BY Raison_Sociale";
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Définir les en-têtes pour le téléchargement
        $filename = 'fournisseurs_export_' . date('Y-m-d') . '.' . $format;
        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            // Créer un fichier CSV
            $output = fopen('php://output', 'w');
            
            // Ajouter l'en-tête UTF-8 BOM pour Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Écrire les en-têtes de colonnes
            fputcsv($output, [
                'ID', 'Code', 'Raison Sociale', 'Adresse', 'Code Postal', 'Ville',
                'Téléphone', 'Email', 'Contact Principal', 'Conditions Paiement',
                'Délai Livraison (jours)', 'Statut', 'Date Création'
            ], ';');
            
            // Écrire les données
            foreach ($fournisseurs as $fournisseur) {
                $row = [
                    $fournisseur['ID_Fournisseur'],
                    $fournisseur['Code_Fournisseur'],
                    $fournisseur['Raison_Sociale'],
                    $fournisseur['Adresse'],
                    $fournisseur['Code_Postal'],
                    $fournisseur['Ville'],
                    $fournisseur['Telephone'],
                    $fournisseur['Email'],
                    $fournisseur['Contact_Principal'],
                    $fournisseur['Conditions_Paiement_Par_Defaut'],
                    $fournisseur['Delai_Livraison_Moyen'],
                    $fournisseur['Actif'] ? 'Actif' : 'Inactif',
                    $fournisseur['Date_Creation']
                ];
                fputcsv($output, $row, ';');
            }
            
            fclose($output);
            exit;
            
        } elseif ($format === 'excel') {
            // Nécessite la bibliothèque PhpSpreadsheet ou une alternative
            // Pour cet exemple, nous allons simuler un export Excel avec un CSV spécial
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            // Créer un fichier CSV formaté pour Excel
            $output = fopen('php://output', 'w');
            
            // Ajouter l'en-tête UTF-8 BOM pour Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Écrire les en-têtes de colonnes
            fputcsv($output, [
                'ID', 'Code', 'Raison Sociale', 'Adresse', 'Code Postal', 'Ville',
                'Téléphone', 'Email', 'Contact Principal', 'Conditions Paiement',
                'Délai Livraison (jours)', 'Statut', 'Date Création'
            ], ';');
            
            // Écrire les données
            foreach ($fournisseurs as $fournisseur) {
                $row = [
                    $fournisseur['ID_Fournisseur'],
                    $fournisseur['Code_Fournisseur'],
                    $fournisseur['Raison_Sociale'],
                    $fournisseur['Adresse'],
                    $fournisseur['Code_Postal'],
                    $fournisseur['Ville'],
                    $fournisseur['Telephone'],
                    $fournisseur['Email'],
                    $fournisseur['Contact_Principal'],
                    $fournisseur['Conditions_Paiement_Par_Defaut'],
                    $fournisseur['Delai_Livraison_Moyen'],
                    $fournisseur['Actif'] ? 'Actif' : 'Inactif',
                    $fournisseur['Date_Creation']
                ];
                fputcsv($output, $row, ';');
            }
            
            fclose($output);
            exit;
            
        } elseif ($format === 'pdf') {
            // Rediriger vers la page d'impression qui sera optimisée pour PDF
            header('Location: print.php?format=pdf&search=' . urlencode($search) . '&status=' . $status . '&delai=' . $delai);
            exit;
        }
        
    } catch (PDOException $e) {
        $error = 'Erreur lors de l\'export des données: ' . $e->getMessage();
        error_log($error);
    }
}

// Inclure l'en-tête
include '../includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Exporter les données fournisseurs</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Export Options Card -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Options d'exportation</h3>
                
                <form action="export.php" method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Format d'export -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Format d'exportation</label>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <input type="radio" id="format_csv" name="format" value="csv" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                    <label for="format_csv" class="ml-2 block text-sm text-gray-700">CSV (compatible Excel)</label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="format_excel" name="format" value="excel" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                    <label for="format_excel" class="ml-2 block text-sm text-gray-700">Excel (.xlsx)</label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="format_pdf" name="format" value="pdf" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                    <label for="format_pdf" class="ml-2 block text-sm text-gray-700">PDF (document imprimable)</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filtres -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filtres</label>
                            
                            <div class="space-y-4">
                                <div class="flex flex-col space-y-2">
                                    <label for="search" class="text-sm text-gray-600">Recherche</label>
                                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Nom, code, ville...">
                                </div>
                                
                                <div class="flex flex-col space-y-2">
                                    <label for="status" class="text-sm text-gray-600">Statut</label>
                                    <select id="status" name="status" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Tous les statuts</option>
                                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Actif</option>
                                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactif</option>
                                    </select>
                                </div>
                                
                                <div class="flex flex-col space-y-2">
                                    <label for="delai" class="text-sm text-gray-600">Délai de livraison</label>
                                    <select id="delai" name="delai" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Tous les délais</option>
                                        <option value="less5" <?= $delai === 'less5' ? 'selected' : '' ?>>< 5 jours</option>
                                        <option value="5to10" <?= $delai === '5to10' ? 'selected' : '' ?>>5-10 jours</option>
                                        <option value="more10" <?= $delai === 'more10' ? 'selected' : '' ?>>> 10 jours</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-lg font-medium text-gray-800 mb-3">Colonnes à inclure</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="flex items-center">
                                <input type="checkbox" id="col_code" name="columns[]" value="code" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_code" class="ml-2 block text-sm text-gray-700">Code</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_raison" name="columns[]" value="raison_sociale" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_raison" class="ml-2 block text-sm text-gray-700">Raison Sociale</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_adresse" name="columns[]" value="adresse" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_adresse" class="ml-2 block text-sm text-gray-700">Adresse</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_cp" name="columns[]" value="code_postal" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_cp" class="ml-2 block text-sm text-gray-700">Code Postal</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_ville" name="columns[]" value="ville" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_ville" class="ml-2 block text-sm text-gray-700">Ville</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_tel" name="columns[]" value="telephone" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_tel" class="ml-2 block text-sm text-gray-700">Téléphone</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_email" name="columns[]" value="email" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_email" class="ml-2 block text-sm text-gray-700">Email</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_contact" name="columns[]" value="contact" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_contact" class="ml-2 block text-sm text-gray-700">Contact</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_conditions" name="columns[]" value="conditions" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_conditions" class="ml-2 block text-sm text-gray-700">Conditions de paiement</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_delai" name="columns[]" value="delai" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_delai" class="ml-2 block text-sm text-gray-700">Délai de livraison</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_statut" name="columns[]" value="statut" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_statut" class="ml-2 block text-sm text-gray-700">Statut</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="col_date" name="columns[]" value="date" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="col_date" class="ml-2 block text-sm text-gray-700">Date de création</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                        <a href="index.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            Retour à la liste
                        </a>
                        <div class="flex space-x-3">
                            <button type="button" id="previewBtn" class="px-4 py-2 border border-blue-500 text-blue-500 rounded-md hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Aperçu
                            </button>
                            <button type="submit" name="export" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Exporter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Preview Card -->
            <div id="previewCard" class="bg-white rounded-lg shadow-md p-6 mb-8 hidden">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Aperçu des données</h3>
                    <button type="button" id="closePreviewBtn" class="text-gray-400 hover:text-gray-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Raison Sociale</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ville</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Délai (jours)</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="previewTableBody">
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">Chargement de l'aperçu...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4 text-sm text-gray-500">
                    <p>* L'aperçu affiche uniquement les 10 premiers résultats. L'export complet contiendra toutes les données correspondant aux filtres sélectionnés.</p>
                </div>
            </div>
            
            <!-- Help Card -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Aide à l'exportation</h3>
                
                <div class="space-y-4">
                    <div>
                        <h4 class="text-lg font-medium text-gray-700">Formats disponibles</h4>
                        <ul class="mt-2 list-disc list-inside text-gray-600 space-y-1">
                            <li><strong>CSV</strong> - Format texte compatible avec Excel et la plupart des tableurs</li>
                            <li><strong>Excel</strong> - Fichier Excel natif (.xlsx)</li>
                            <li><strong>PDF</strong> - Document PDF formaté pour l'impression</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-medium text-gray-700">Filtres</h4>
                        <p class="mt-2 text-gray-600">Utilisez les filtres pour limiter les données exportées selon vos besoins. Vous pouvez filtrer par mot-clé, statut ou délai de livraison.</p>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-medium text-gray-700">Colonnes</h4>
                        <p class="mt-2 text-gray-600">Sélectionnez les colonnes que vous souhaitez inclure dans l'export. Par défaut, toutes les colonnes sont sélectionnées.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Gestion de l'aperçu
        const previewBtn = document.getElementById('previewBtn');
        const closePreviewBtn = document.getElementById('closePreviewBtn');
        const previewCard = document.getElementById('previewCard');
        const previewTableBody = document.getElementById('previewTableBody');
        
        previewBtn.addEventListener('click', function() {
            // Afficher la carte d'aperçu
            previewCard.classList.remove('hidden');
            
            // Récupérer les valeurs des filtres
            const search = document.getElementById('search').value;
            const status = document.getElementById('status').value;
            const delai = document.getElementById('delai').value;
            
            // Effectuer une requête AJAX pour obtenir l'aperçu
            fetch(`get_fournisseurs_preview.php?search=${encodeURIComponent(search)}&status=${status}&delai=${delai}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erreur réseau lors de la récupération des données');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    // Générer le contenu du tableau d'aperçu
                    if (data.fournisseurs && data.fournisseurs.length > 0) {
                        let html = '';
                        data.fournisseurs.forEach(fournisseur => {
                            const statusClass = fournisseur.Actif == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                            const statusText = fournisseur.Actif == 1 ? 'Actif' : 'Inactif';
                            
                            html += `
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${fournisseur.Code_Fournisseur || '-'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${fournisseur.Raison_Sociale || '-'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${fournisseur.Ville || '-'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${fournisseur.Contact_Principal || '-'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${fournisseur.Delai_Livraison_Moyen || '-'}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">
                                            ${statusText}
                                        </span>
                                    </td>
                                </tr>
                            `;
                        });
                        previewTableBody.innerHTML = html;
                    } else {
                        previewTableBody.innerHTML = '<tr><td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">Aucun fournisseur trouvé avec les filtres actuels</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    previewTableBody.innerHTML = `<tr><td colspan="6" class="px-6 py-4 text-center text-sm text-red-500">Erreur lors du chargement de l'aperçu: ${error.message}</td></tr>`;
                });
            
            // Faire défiler jusqu'à la carte d'aperçu
            previewCard.scrollIntoView({ behavior: 'smooth' });
        });
        
        closePreviewBtn.addEventListener('click', function() {
            previewCard.classList.add('hidden');
        });
        // Gestion des formats d'export
        const formatRadios = document.querySelectorAll('input[name="format"]');
        formatRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const formatValue = this.value;
                
                // Si PDF est sélectionné, masquer la sélection des colonnes (car tout sera inclus)
                const columnsSection = document.querySelector('.border-t.border-gray-200.pt-4');
                if (formatValue === 'pdf') {
                    columnsSection.classList.add('opacity-50', 'pointer-events-none');
                } else {
                    columnsSection.classList.remove('opacity-50', 'pointer-events-none');
                }
            });
        });
        
        // Gestion du formulaire d'export
        const exportForm = document.querySelector('form');
        exportForm.addEventListener('submit', function(event) {
            // Vérifier qu'au moins une colonne est sélectionnée
            const selectedColumns = document.querySelectorAll('input[name="columns[]"]:checked');
            if (selectedColumns.length === 0) {
                event.preventDefault();
                alert('Veuillez sélectionner au moins une colonne à exporter.');
                return false;
            }
            
            // Si format PDF, rediriger vers print.php
            const selectedFormat = document.querySelector('input[name="format"]:checked').value;
            if (selectedFormat === 'pdf') {
                event.preventDefault();
                
                const search = document.getElementById('search').value;
                const status = document.getElementById('status').value;
                const delai = document.getElementById('delai').value;
                
                // Rediriger vers la page d'impression
                window.location.href = `print.php?format=pdf&search=${encodeURIComponent(search)}&status=${status}&delai=${delai}`;
                return false;
            }
        });
        
        // Fonction pour sélectionner/désélectionner toutes les colonnes
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'text-sm text-blue-500 hover:text-blue-700 focus:outline-none';
        selectAllBtn.textContent = 'Tout sélectionner';
        
        const deselectAllBtn = document.createElement('button');
        deselectAllBtn.type = 'button';
        deselectAllBtn.className = 'text-sm text-blue-500 hover:text-blue-700 focus:outline-none ml-4';
        deselectAllBtn.textContent = 'Tout désélectionner';
        
        const columnsHeader = document.querySelector('.border-t.border-gray-200.pt-4 h4');
        columnsHeader.appendChild(document.createTextNode(' '));
        columnsHeader.appendChild(selectAllBtn);
        columnsHeader.appendChild(deselectAllBtn);
        
        selectAllBtn.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[name="columns[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        });
        
        deselectAllBtn.addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[name="columns[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
