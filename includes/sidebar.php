<?php
// Définir la page active
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Fonction pour vérifier si un lien est actif
function is_active($page, $dir = '') {
    global $current_page, $current_dir;
    
    if (empty($dir)) {
        return $current_page == $page ? 'bg-indigo-800 text-white' : 'text-indigo-100 hover:bg-indigo-700 hover:text-white';
    } else {
        return $current_dir == $dir ? 'bg-indigo-800 text-white' : 'text-indigo-100 hover:bg-indigo-700 hover:text-white';
    }
}
?>

<!-- Sidebar -->
<div class="w-64 bg-indigo-900 text-white flex flex-col h-screen">
    <!-- Logo -->
    <div class="px-4 py-5 flex items-center justify-center">
        <span class="text-2xl font-semibold tracking-wider">SAS Auto</span>
    </div>
    <!-- User info -->
    <div class="px-4 py-3 border-t border-b border-indigo-800">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-full bg-indigo-700 flex items-center justify-center text-xl font-semibold mr-3">
                <?php echo substr(htmlspecialchars($currentUser['name']), 0, 1); ?>
            </div>
            <div>
                <p class="text-sm font-medium"><?php echo htmlspecialchars($currentUser['name']); ?></p>
                <p class="text-xs text-indigo-300"><?php echo htmlspecialchars($currentUser['role']); ?></p>
            </div>
        </div>
    </div>
    
    <!-- Navigation -->
    <nav class="mt-4 flex-1 overflow-y-auto">
        <ul>
            <!-- Dashboard -->
            <li>
                <a href="/sas-gestion-auto/dashboard.php" class="flex items-center px-6 py-3 <?php echo is_active('dashboard.php'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                    <span>Tableau de bord</span>
                </a>
            </li>
            
            <!-- Clients -->
            <li>
                <a href="/sas-gestion-auto/clients/" class="flex items-center px-6 py-3 <?php echo is_active('', 'clients'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <span>Clients</span>
                </a>
            </li>
            <!-- Fournisseurs -->
            <li>
                <a href="/sas-gestion-auto/fournisseurs/" class="flex items-center px-6 py-3 <?php echo is_active('', 'fournisseurs'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17H3V5h13v4m0 4h5l3 4v2h-2m-4-6V9a2 2 0 00-2-2h-4M5 21h2m10 0h2M3 17h18m-6 4a2 2 0 100-4 2 2 0 000 4m-10 0a2 2 0 100-4 2 2 0 000 4"></path>
                    </svg>
                    <span>Fournisseurs</span>
                </a>
            </li>

            
            <!-- Vehicles -->
            <li>
                <a href="/sas-gestion-auto/vehicles/view.php" class="flex items-center px-6 py-3 <?php echo is_active('', 'vehicles'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    <span>Véhicules</span>
                </a>
            </li>
            
            <!-- Interventions -->
            <li>
                <a href="/sas-gestion-auto/interventions/" class="flex items-center px-6 py-3 <?php echo is_active('', 'interventions'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>Interventions</span>
                </a>
            </li>
            
            <!-- Stock -->
            <li>
                <a href="/sas-gestion-auto/stock/" class="flex items-center px-6 py-3 <?php echo is_active('', 'stock'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>Stock</span>
                </a>
            </li>
            <!-- Offres -->
            <li>
                <a href="/sas-gestion-auto/offres/" class="flex items-center px-6 py-3 <?php echo is_active('', 'offres'); ?>">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7a1 1 0 011-1h4.586a1 1 0 01.707.293l7.414 7.414a1 1 0 010 1.414l-4.586 4.586a1 1 0 01-1.414 0L4.293 9.707A1 1 0 014 9V8a1 1 0 011-1h2z" />
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01" />
</svg>


                    <span>Offres</span>
                </a>
            </li>
            
            <!-- Invoices -->
            <li>
                <a href="/sas-gestion-auto/invoices/" class="flex items-center px-6 py-3 <?php echo is_active('', 'invoices'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Factures</span>
                </a>
            </li>
            
            <!-- Orders -->
            <li>
                <a href="/sas-gestion-auto/orders/" class="flex items-center px-6 py-3 <?php echo is_active('', 'orders'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span>Commandes</span>
                </a>
            </li>
            
            <!-- Deliveries -->
          <!--   <li>
                <a href="/sas-gestion-auto/deliveries/" class="flex items-center px-6 py-3 <?php echo is_active('', 'deliveries'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"></path>
                    </svg>
                    <span>Livraisons</span>
                </a>
            </li> -->
            
            <!-- Users -->
            <li>
                <a href="/sas-gestion-auto/users/" class="flex items-center px-6 py-3 <?php echo is_active('', 'users'); ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <span>Utilisateurs</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- Footer -->
    <div class="border-t border-indigo-800 p-4">
        <a href="/sas-gestion-auto/logout.php" class="flex items-center text-indigo-100 hover:text-white transition duration-200">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            <span>Déconnexion</span>
        </a>
    </div>
</div>