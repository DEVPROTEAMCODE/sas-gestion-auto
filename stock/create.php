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
include '../includes/header.php';

$database = new Database();
$db = $database->getConnection();

// Récupérer toutes les catégories de la base de données
$categoriesQuery = "SELECT * FROM categorie ORDER BY nom";
$categoriesStmt = $db->prepare($categoriesQuery);
$categoriesStmt->execute();
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire
$errors = [];
$success = false;
$message = '';
$article_added = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    if (empty($_POST['reference'])) {
        $errors['reference'] = 'La référence est requise';
    }
    if (empty($_POST['designation'])) {
        $errors['designation'] = 'La désignation est requise';
    }

    if (empty($_POST['categorie'])) {
        $errors['categorie'] = 'La catégorie est requise';
    }

    if (empty($_POST['prix_achat'])) {
        $errors['prix_achat'] = 'Le prix d\'achat est requis';
    } else if ($_POST['prix_achat'] <= 0) {
        $errors['prix_achat'] = 'Le prix d\'achat doit être supérieur à 0';
    }

    if (empty($_POST['marge_benifice'])) {
        $errors['marge_benifice'] = 'La marge bénéfice est requise';
    } else if ($_POST['marge_benifice'] < 0 || $_POST['marge_benifice'] > 100) {
        $errors['marge_benifice'] = 'La marge doit être comprise entre 0 et 100%';
    }

    if (empty($_POST['quantite_stock'])) {
        $errors['quantite_stock'] = 'La quantité en stock est requise';
    } else if ($_POST['quantite_stock'] < 0) {
        $errors['quantite_stock'] = 'La quantité doit être un nombre positif';
    }

    if (empty($_POST['seuil_alerte'])) {
        $errors['seuil_alerte'] = 'Le seuil d\'alerte est requis';
    } else if ($_POST['seuil_alerte'] < 0) {
        $errors['seuil_alerte'] = 'Le seuil d\'alerte doit être un nombre positif';
    }

    if (empty($_POST['emplacement'])) {
        $errors['emplacement'] = 'L\'emplacement est requis';
    }

    // Si aucune erreur, insérer l'article dans la base de données
    if (empty($errors)) {
        try {
            // Utiliser la connexion de database.php
            global $db;

            // Calcul des prix de vente
            $prix_achat = floatval($_POST['prix_achat']);
            $marge = floatval($_POST['marge_benifice']);
            
            $prix_vente_ht = $prix_achat * (1 + $marge / 100);

            // Préparer la requête d'insertion
            $query = "INSERT INTO articles (
                        reference, 
                        designation, 
                        categorie_id, 
                        emplacement, 
                        prix_achat, 
                        marge_benifice,  
                        prix_vente_ht, 
                        quantite_stock, 
                        seuil_alerte, 
                        date_creation, 
                        derniere_mise_a_jour
                      ) VALUES (
                        :reference, 
                        :designation, 
                        :categorie, 
                        :emplacement, 
                        :prix_achat, 
                        :marge_benifice, 
                        :prix_vente_ht, 
                        :quantite_stock, 
                        :seuil_alerte, 
                        :date_creation, 
                        :derniere_mise_a_jour
                      )";
            
            $stmt = $db->prepare($query);

            // Binder les paramètres
            $stmt->bindParam(':reference', $_POST['reference']);
            $stmt->bindParam(':designation', $_POST['designation']);
            $stmt->bindParam(':categorie', $_POST['categorie']);
            $stmt->bindParam(':emplacement', $_POST['emplacement']);
            $stmt->bindParam(':prix_achat', $prix_achat);
            $stmt->bindParam(':marge_benifice', $marge);
            $stmt->bindParam(':prix_vente_ht', $prix_vente_ht);
            $stmt->bindParam(':quantite_stock', $_POST['quantite_stock'], PDO::PARAM_INT);
            $stmt->bindParam(':seuil_alerte', $_POST['seuil_alerte'], PDO::PARAM_INT);
            
            $date_creation = date('Y-m-d H:i:s');
            $stmt->bindParam(':date_creation', $date_creation);
            $stmt->bindParam(':derniere_mise_a_jour', $date_creation);

            // Exécuter la requête
            if ($stmt->execute()) {
                $success = true;
                $article_added = true;
                $message = "L'article a été ajouté avec succès!";
            } else {
                $message = "Erreur lors de l'ajout de l'article.";
            }
        } catch (PDOException $e) {
            $message = "Erreur de base de données: " . $e->getMessage();
        }
    }
}

// Inclure l'en-tête
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto p-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Gestion des Articles</h1>
            
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo $message; ?>
                </div>
                
                <!-- Boutons de navigation après ajout réussi -->
                <div class="flex space-x-4 mb-6">
                    <a href="index.php" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Retour à la liste des articles
                    </a>
                    <a href="add.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Ajouter un autre article
                    </a>
                </div>
            <?php elseif (!empty($message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$article_added): ?>
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-2">Ajout d'un Nouvel Article</h2>
                <p class="text-gray-600">Tous les champs marqués d'un * sont obligatoires</p>
            </div>
            
            <form id="article-form" method="POST" action="">
                <!-- Section Informations Générales -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Informations Générales</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="reference" class="block text-sm font-medium text-gray-700 mb-1">Référence*</label>
                            <input type="text" id="reference" name="reference" placeholder="Entrez la référence" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['reference']) ? htmlspecialchars($_POST['reference']) : ''; ?>">
                            <?php if (isset($errors['reference'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['reference']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="designation" class="block text-sm font-medium text-gray-700 mb-1">Désignation*</label>
                            <input type="text" id="designation" name="designation" placeholder="Nom de l'article" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['designation']) ? htmlspecialchars($_POST['designation']) : ''; ?>">
                            <?php if (isset($errors['designation'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['designation']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label for="categorie" class="block text-sm font-medium text-gray-700 mb-1">Catégorie*</label>
                            <select id="categorie" name="categorie" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner une catégorie</option>
                                <?php foreach ($categories as $categorie): ?>
                                    <?php $selected = (isset($_POST['categorie']) && $_POST['categorie'] == $categorie['id']) ? 'selected' : ''; ?>
                                    <option value="<?php echo $categorie['id']; ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($categorie['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['categorie'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['categorie']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="emplacement" class="block text-sm font-medium text-gray-700 mb-1">Emplacement*</label>
                            <select id="emplacement" name="emplacement" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner un emplacement</option>
                                <?php
                                $emplacements = [
                                    'Casa Roche noir', 'Casa Aeroport', 'Rabat', 'Marrakech', 
                                    'Marrakech Aeroport'
                                ];
                                foreach ($emplacements as $emp) {
                                    $selected = (isset($_POST['emplacement']) && $_POST['emplacement'] === $emp) ? 'selected' : '';
                                    echo "<option value=\"$emp\" $selected>$emp</option>";
                                }
                                ?>
                            </select>
                            <?php if (isset($errors['emplacement'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['emplacement']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Section Informations Financières -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Informations Financières</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="prix_achat" class="block text-sm font-medium text-gray-700 mb-1">Prix d'achat (DH HT)*</label>
                            <input type="number" id="prix_achat" name="prix_achat" step="0.01" min="0" placeholder="0.00" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['prix_achat']) ? htmlspecialchars($_POST['prix_achat']) : ''; ?>">
                            <?php if (isset($errors['prix_achat'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['prix_achat']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="marge_benifice" class="block text-sm font-medium text-gray-700 mb-1">Marge bénéfice (%)*</label>
                            <input type="number" id="marge_benifice" name="marge_benifice" step="0.1" min="0" max="100" placeholder="20.0" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['marge_benifice']) ? htmlspecialchars($_POST['marge_benifice']) : ''; ?>">
                            <?php if (isset($errors['marge_benifice'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['marge_benifice']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mt-4">
                        <div>
                            <label for="prix_vente_ht" class="block text-sm font-medium text-gray-700 mb-1">Prix de vente (DH HT)</label>
                            <input type="number" id="prix_vente_ht" name="prix_vente_ht" step="0.01" min="0" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md">
                            <span class="text-xs text-gray-500 mt-1 block">Calculé automatiquement</span>
                        </div>
                    </div>
                </div>
                
                <!-- Section Gestion des Stocks -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Gestion des Stocks</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="quantite_stock" class="block text-sm font-medium text-gray-700 mb-1">Quantité en stock*</label>
                            <input type="number" id="quantite_stock" name="quantite_stock" min="0" placeholder="0" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['quantite_stock']) ? htmlspecialchars($_POST['quantite_stock']) : ''; ?>">
                            <?php if (isset($errors['quantite_stock'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['quantite_stock']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="seuil_alerte" class="block text-sm font-medium text-gray-700 mb-1">Seuil d'alerte*</label>
                            <input type="number" id="seuil_alerte" name="seuil_alerte" min="0" placeholder="5" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['seuil_alerte']) ? htmlspecialchars($_POST['seuil_alerte']) : ''; ?>">
                            <span class="text-xs text-gray-500 mt-1 block">Niveau minimum avant réapprovisionnement</span>
                            <?php if (isset($errors['seuil_alerte'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['seuil_alerte']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Section Dates (auto-générées) -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Dates</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="date_creation" class="block text-sm font-medium text-gray-700 mb-1">Date de création</label>
                            <input type="date" id="date_creation" name="date_creation" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md">
                            <span class="text-xs text-gray-500 mt-1 block">Générée automatiquement</span>
                        </div>
                        <div>
                            <label for="derniere_mise_a_jour" class="block text-sm font-medium text-gray-700 mb-1">Dernière mise à jour</label>
                            <input type="date" id="derniere_mise_a_jour" name="derniere_mise_a_jour" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md">
                            <span class="text-xs text-gray-500 mt-1 block">Générée automatiquement</span>
                        </div>
                    </div>
                </div>
                
                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-4 mt-6">
                    <button type="button" id="preview-btn" 
                        class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Aperçu
                    </button>
                    <button type="button" id="reset-btn" 
                        class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Réinitialiser
                    </button>
                    <button type="submit" id="submit-btn" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Ajouter l'article
                    </button>
                </div>
            </form>
            
            <!-- Container pour l'aperçu des données -->
            <div id="preview-container" class="mt-8 border border-gray-300 rounded-lg p-6 hidden">
                <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-700">Aperçu des données à insérer</h3>
                    <button type="button" id="close-preview" class="text-gray-500 hover:text-gray-700">&times;</button>
                </div>
                <div id="preview-content" class="mb-6">
                    <!-- Contenu généré dynamiquement -->
                </div>
                <div class="bg-gray-100 p-4 rounded-md">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Requête SQL générée</h4>
                    <pre id="sql-preview" class="text-xs overflow-x-auto font-mono"></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Set current date for date fields
        const today = new Date();
        const formattedDate = formatDate(today);
        document.getElementById('date_creation').value = formattedDate;
        document.getElementById('derniere_mise_a_jour').value = formattedDate;
        
        // Setup price calculations
        setupPriceCalculations();
        
        // Setup form validation
        setupValidation();
        
        // Setup form actions
        setupFormActions();
    });
    
    // Format date to YYYY-MM-DD
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    
    // Setup automatic price calculations
    function setupPriceCalculations() {
        const prixAchatInput = document.getElementById('prix_achat');
        const margeBenificeInput = document.getElementById('marge_benifice');
        const prixVenteHtInput = document.getElementById('prix_vente_ht');
        
        if (!prixAchatInput || !margeBenificeInput || !prixVenteHtInput) return;
        
        // Function to calculate prices
        function calculatePrices() {
            const prixAchat = parseFloat(prixAchatInput.value) || 0;
            const margeBenifice = parseFloat(margeBenificeInput.value) || 0;
            
            // Calculate HT price with margin
            const prixVenteHt = prixAchat * (1 + margeBenifice / 100);
            
            // Update fields with 2 decimal places
            prixVenteHtInput.value = prixVenteHt.toFixed(2);
        }
        
        // Add event listeners
        prixAchatInput.addEventListener('input', calculatePrices);
        margeBenificeInput.addEventListener('input', calculatePrices);
        
        // Calculate initial values if form was submitted and has errors
        calculatePrices();
    }
    
    // Setup form validation
    function setupValidation() {
        const form = document.getElementById('article-form');
        if (!form) return;
        
        const fields = {
            reference: {
                required: true,
                errorMsg: 'La référence est requise'
            },
            designation: {
                required: true,
                minLength: 3,
                errorMsg: 'Minimum 3 caractères requis'
            },
            categorie: {
                required: true,
                errorMsg: 'Veuillez sélectionner une catégorie'
            },
            prix_achat: {
                required: true,
                min: 0.01,
                errorMsg: 'Le prix d\'achat doit être supérieur à 0'
            },
            marge_benifice: {
                required: true,
                min: 0,
                max: 100,
                errorMsg: 'La marge doit être comprise entre 0 et 100%'
            },
            quantite_stock: {
                required: true,
                min: 0,
                errorMsg: 'La quantité doit être un nombre positif'
            },
            seuil_alerte: {
                required: true,
                min: 0,
                errorMsg: 'Le seuil d\'alerte doit être un nombre positif'
            },
            emplacement: {
                required: true,
                errorMsg: 'Veuillez sélectionner un emplacement'
            }
        };
        
        // Validate a single field
        function validateField(fieldName, value) {
            const field = fields[fieldName];
            let errorElement = document.getElementById(`${fieldName}-error`);
            
            if (!field) return true;
            
            // Create error element if it doesn't exist
            if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.id = `${fieldName}-error`;
                errorElement.className = 'text-red-500 text-xs mt-1';
                const fieldElement = document.getElementById(fieldName);
                if (fieldElement && fieldElement.parentNode) {
                    fieldElement.parentNode.appendChild(errorElement);
                }
            }
            
            // Check required
            if (field.required && !value) {
                errorElement.textContent = 'Ce champ est obligatoire';
                return false;
            }
            
            // Check min length
            if (field.minLength && value && value.length < field.minLength) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Check min value
            if (field.min !== undefined && value !== '' && parseFloat(value) < field.min) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Check max value
            if (field.max !== undefined && value !== '' && parseFloat(value) > field.max) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Clear error if validation passes
            errorElement.textContent = '';
            return true;
        }
        
        // Add input event listeners to all fields
        Object.keys(fields).forEach(fieldName => {
            const element = document.getElementById(fieldName);
            if (element) {
                element.addEventListener('input', function() {
                    validateField(fieldName, this.value);
                });
                
                element.addEventListener('blur', function() {
                    validateField(fieldName, this.value);
                });
            }
        });
        
        // Validate all fields before form submission
        form.addEventListener('submit', function(event) {
            let isValid = true;
            
            Object.keys(fields).forEach(fieldName => {
                const element = document.getElementById(fieldName);
                if (element) {
                    const fieldValid = validateField(fieldName, element.value);
                    isValid = isValid && fieldValid;
                }
            });
            
            if (!isValid) {
                event.preventDefault();
                showStatus('Veuillez corriger les erreurs dans le formulaire.', 'error');
            }
        });
    }
    
    // Setup form actions (preview, reset, etc.)
    function setupFormActions() {
        const form = document.getElementById('article-form');
        if (!form) return;
        
        const previewBtn = document.getElementById('preview-btn');
        const resetBtn = document.getElementById('reset-btn');
        const closePreviewBtn = document.getElementById('close-preview');
        const previewContainer = document.getElementById('preview-container');
        
        if (previewBtn && previewContainer) {
            // Preview button click
            previewBtn.addEventListener('click', function() {
                // Validate form first
                let isValid = true;
                const formData = new FormData(form);
                
                // Simple validation check
                for (const [key, value] of formData.entries()) {
                    const element = document.getElementById(key);
                    if (element && element.required && !value) {
                        isValid = false;
                        break;
                    }
                }
                
                if (!isValid) {
                    showStatus('Veuillez remplir tous les champs obligatoires avant de prévisualiser.', 'error');
                    return;
                }
                
                // Generate preview content
                const previewContent = document.getElementById('preview-content');
                const sqlPreview = document.getElementById('sql-preview');
                
                // Create HTML table for preview
                               // Create HTML table for preview
                               let tableHTML = '<table class="min-w-full divide-y divide-gray-200">';
                tableHTML += '<thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Champ</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valeur</th></tr></thead>';
                tableHTML += '<tbody class="bg-white divide-y divide-gray-200">';
                
                const fieldLabels = {
                    reference: 'Référence',
                    designation: 'Désignation',
                    categorie: 'Catégorie',
                    emplacement: 'Emplacement',
                    prix_achat: 'Prix d\'achat (DH HT)',
                    marge_benifice: 'Marge bénéfice (%)',
                    prix_vente_ht: 'Prix de vente (DH HT)',
                    quantite_stock: 'Quantité en stock',
                    seuil_alerte: 'Seuil d\'alerte',
                    date_creation: 'Date de création',
                    derniere_mise_a_jour: 'Dernière mise à jour'
                };
                
                for (const [key, value] of formData.entries()) {
                    // Skip readonly fields that will be auto-generated
                    if (key === 'prix_vente_ht') continue;
                    
                    // Special handling for category dropdown to show name instead of ID
                    if (key === 'categorie') {
                        const categorySelect = document.getElementById('categorie');
                        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                        const categoryName = selectedOption.textContent;
                        tableHTML += `<tr><td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${fieldLabels[key] || key}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${categoryName} (ID: ${value})</td></tr>`;
                        continue;
                    }
                    
                    const label = fieldLabels[key] || key;
                    tableHTML += `<tr><td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${label}</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${value}</td></tr>`;
                }
                
                // Add calculated fields
                const prixVenteHt = document.getElementById('prix_vente_ht').value;
                
                tableHTML += `<tr><td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Prix de vente (DH HT)</td><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${prixVenteHt}</td></tr>`;
                
                tableHTML += '</tbody></table>';
                previewContent.innerHTML = tableHTML;
                
                // Generate SQL preview
                let sqlQuery = 'INSERT INTO article (';
                let sqlValues = 'VALUES (';
                
                const fieldNames = [];
                const values = [];
                
                for (const [key, value] of formData.entries()) {
                    // Skip calculated fields
                    if (key === 'prix_vente_ht') continue;
                    
                    fieldNames.push(key);
                    
                    // Format value based on type
                    if (key === 'prix_achat' || key === 'marge_benifice' || 
                        key === 'quantite_stock' || key === 'seuil_alerte') {
                        values.push(value);
                    } else {
                        values.push(`'${value}'`);
                    }
                }
                
                // Add calculated fields
                fieldNames.push('prix_vente_ht');
                values.push(prixVenteHt);
                
                sqlQuery += fieldNames.join(', ') + ') ';
                sqlQuery += 'VALUES (' + values.join(', ') + ')';
                
                sqlPreview.textContent = sqlQuery;
                
                // Show preview container
                previewContainer.classList.remove('hidden');
            });
        }
        
        if (closePreviewBtn && previewContainer) {
            // Close preview button
            closePreviewBtn.addEventListener('click', function() {
                previewContainer.classList.add('hidden');
            });
        }
        
        if (resetBtn && form) {
            // Reset button click
            resetBtn.addEventListener('click', function() {
                form.reset();
                
                // Reset validation errors
                const errorElements = document.querySelectorAll('[id$="-error"]');
                errorElements.forEach(element => {
                    element.textContent = '';
                });
                
                // Reset calculated fields
                const prixVenteHtInput = document.getElementById('prix_vente_ht');
                if (prixVenteHtInput) {
                    prixVenteHtInput.value = '';
                }
                
                // Reset date fields
                const today = new Date();
                const formattedDate = formatDate(today);
                const dateCreationInput = document.getElementById('date_creation');
                const derniereMiseAJourInput = document.getElementById('derniere_mise_a_jour');
                
                if (dateCreationInput) {
                    dateCreationInput.value = formattedDate;
                }
                
                if (derniereMiseAJourInput) {
                    derniereMiseAJourInput.value = formattedDate;
                }
                
                showStatus('Le formulaire a été réinitialisé.', 'success');
            });
        }
    }
    
    // Show status message
    function showStatus(message, type) {
        // Create status container if it doesn't exist
        let statusContainer = document.getElementById('status-message');
        if (!statusContainer) {
            statusContainer = document.createElement('div');
            statusContainer.id = 'status-message';
            const bgWhite = document.querySelector('.bg-white');
            const articleForm = document.getElementById('article-form');
            
            if (bgWhite && articleForm) {
                bgWhite.insertBefore(statusContainer, articleForm);
            } else if (bgWhite) {
                bgWhite.prepend(statusContainer);
            } else {
                document.body.prepend(statusContainer);
            }
        }
        
        // Set content and styling
        statusContainer.textContent = message;
        statusContainer.className = 'px-4 py-3 rounded mb-4 ';
        
        if (type === 'success') {
            statusContainer.className += 'bg-green-100 border border-green-400 text-green-700';
        } else {
            statusContainer.className += 'bg-red-100 border border-red-400 text-red-700';
        }
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            statusContainer.remove();
        }, 5000);
    }
</script>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>

