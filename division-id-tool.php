<?php
// ==============================
// division_id_tool.php — Version PHP autonome
// Aucune dépendance externe requise - utilise uniquement les fonctions standard PHP
// ==============================

// Préfixes typologiques francophones
$PREFIX_MAP = [
    "pays" => "PA", "region" => "RG", "prefecture" => "PR", "province" => "PV",
    "departement" => "DP", "district" => "DI", "arrondissement" => "AR", "commune" => "CM",
    "ville" => "VL", "quartier" => "QR", "localite" => "LC", "secteur-villageois" => "SV",
    "village" => "VG", "zone-sanitaire" => "ZS", "zone-de-developpement" => "ZD",
    "zone-electorale" => "ZE", "canton" => "CC", "territoire" => "TR", "metropole" => "MT"
];

// Custom slugify function to match JavaScript and Python behavior
function slugify($text, $separator = "-", $lowercase = true) {
    // Normalize Unicode characters (decompose)
    if (class_exists('Normalizer')) {
        $text = Normalizer::normalize($text, Normalizer::FORM_D);
    }
    
    // Remove accents (combining characters)
    $text = preg_replace('/[\x{0300}-\x{036F}]/u', '', $text);
    
    if ($lowercase) {
        $text = mb_strtolower($text, 'UTF-8');
    }
    
    // Replace non-alphanumeric characters with separator
    $text = preg_replace('/[^a-zA-Z0-9]+/', $separator, $text);
    
    // Remove leading/trailing separators
    $text = trim($text, $separator);
    
    return $text;
}

// RFC4648 Base32 encoding function
function rfc4648Base32Encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $result = '';
    $bits = 0;
    $value = 0;
    
    for ($i = 0; $i < strlen($data); $i++) {
        $value = ($value << 8) | ord($data[$i]);
        $bits += 8;
        
        while ($bits >= 5) {
            $result .= $alphabet[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    
    if ($bits > 0) {
        $result .= $alphabet[($value << (5 - $bits)) & 31];
    }
    
    return $result;
}

function normalizedSlug($pathArray) {
    $slugs = [];
    foreach ($pathArray as $name) {
        $slugs[] = slugify($name, "-", true);
    }
    return implode(".", $slugs);
}

function generateDivisionId($prefix, $pathArray, $prefixDelimiter = '') {
    $slug = normalizedSlug($pathArray);
    $hash = hash('sha256', $slug, true);
    $base32Id = rfc4648Base32Encode($hash);
    
    return [
        'division_id' => $prefix . $prefixDelimiter . substr($base32Id, 0, 6),
        'division_slug' => $slug
    ];
}

function generateSqlInserts($inputFile, $outputFile) {
    global $PREFIX_MAP;
    
    if (!file_exists($inputFile)) {
        echo "❌ Fichier d'entrée non trouvé: $inputFile\n";
        exit(1);
    }
    
    // Read CSV headers
    $handle = fopen($inputFile, 'r');
    if ($handle === false) {
        echo "❌ Impossible d'ouvrir le fichier: $inputFile\n";
        exit(1);
    }
    
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        echo "❌ Impossible de lire les en-têtes du fichier CSV\n";
        fclose($handle);
        exit(1);
    }
    
    // Generate division types from headers
    $divisionTypes = [];
    $divisionTypeMap = [];
    $typeIdCounter = 1;
    
    foreach ($headers as $level => $headerName) {
        $typeSlug = slugify($headerName, "-", true);
        
        if (!isset($PREFIX_MAP[$typeSlug])) {
            echo "❌ Type de division inconnu: '$typeSlug'. Veuillez mettre à jour le tableau de correspondance.\n";
            exit(1);
        }
        
        $prefix = $PREFIX_MAP[$typeSlug];
        $parentTypeId = $level > 0 ? $level : 'null';
        
        $typeInfo = [
            'id' => $typeIdCounter,
            'name' => $headerName,
            'code' => $prefix,
            'level' => $level,
            'parent_type_id' => $parentTypeId,
            'description' => "Type de division: $headerName"
        ];
        
        $divisionTypes[] = $typeInfo;
        $divisionTypeMap[$headerName] = $typeInfo;
        $typeIdCounter++;
    }
    
    // Generate divisions with parent relationships
    $divisions = [];
    $divisionMap = [];
    
    // Reset to beginning
    rewind($handle);
    fgetcsv($handle, 0, ',', '"', '\\'); // Skip header
    
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        // Ensure we have values for all headers, padding with empty strings if needed
        $fullRow = [];
        for ($i = 0; $i < count($headers); $i++) {
            $fullRow[] = isset($row[$i]) ? trim($row[$i]) : '';
        }
        
        $pathArray = array_filter($fullRow, function($v) { return $v !== ''; });
        
        // Skip if no data
        if (count($pathArray) === 0) continue;
        
        // Validate: no empty columns in the middle
        $lastIdx = -1;
        for ($i = 1; $i < count($fullRow); $i++) {
            if ($fullRow[$i] === '' && $fullRow[$i-1] !== '') {
                $lastIdx = $i;
                break;
            }
        }
        
        $isValid = $lastIdx === -1 || array_reduce(
            array_slice($fullRow, $lastIdx), 
            function($carry, $item) { return $carry && $item === ''; }, 
            true
        );
        
        if (!$isValid) continue;
        
        $pathArray = array_values($pathArray); // Re-index array
        $typeName = $headers[count($pathArray) - 1];
        $typeSlug = slugify($typeName, "-", true);
        $prefix = $PREFIX_MAP[$typeSlug];
        
        $divisionData = generateDivisionId($prefix, $pathArray, '-');
        
        // Calculate parent_id by getting the division_id of the first n-1 elements
        $parentId = null;
        if (count($pathArray) > 1) {
            $parentPathArray = array_slice($pathArray, 0, -1);
            $parentTypeName = $headers[count($parentPathArray) - 1];
            $parentTypeSlug = slugify($parentTypeName, "-", true);
            $parentPrefix = $PREFIX_MAP[$parentTypeSlug];
            $parentDivisionData = generateDivisionId($parentPrefix, $parentPathArray, '-');
            $parentId = $parentDivisionData['division_id'];
        }
        
        $typeInfo = $divisionTypeMap[$typeName];
        
        $division = [
            'id' => $divisionData['division_id'],
            'type_id' => $typeInfo['id'],
            'parent_id' => $parentId,
            'name' => $pathArray[count($pathArray) - 1], // Last element is the name
            'division_slug' => $divisionData['division_slug']
        ];
        
        $divisions[] = $division;
        $divisionMap[$divisionData['division_id']] = $division;
    }
    
    fclose($handle);
    
    // Generate SQL
    $sql = "-- SQL Insert Script for Country Tree Structure\n";
    $sql .= "-- Generated by division-id-tool.php\n\n";
    
    // Insert division types
    $sql .= "-- Insert division types\n";
    foreach ($divisionTypes as $type) {
        $name = str_replace("'", "''", $type['name']);
        $description = str_replace("'", "''", $type['description']);
        $sql .= "INSERT INTO country_division_types (id, name, code, level, parent_type_id, description) VALUES ";
        $sql .= "({$type['id']}, '{$name}', '{$type['code']}', {$type['level']}, {$type['parent_type_id']}, '{$description}');\n";
    }
    
    $sql .= "\n-- Insert divisions\n";
    foreach ($divisions as $division) {
        $parentIdValue = $division['parent_id'] ? "'{$division['parent_id']}'" : 'NULL';
        $name = str_replace("'", "''", $division['name']);
        $sql .= "INSERT INTO country_divisions (id, type_id, parent_id, name, division_slug) VALUES ";
        $sql .= "('{$division['id']}', {$division['type_id']}, {$parentIdValue}, '{$name}', '{$division['division_slug']}');\n";
    }
    
    // Write SQL to file
    if (file_put_contents($outputFile, $sql) === false) {
        echo "❌ Impossible de créer le fichier de sortie: $outputFile\n";
        exit(1);
    }
    
    echo "✅ Script SQL généré : $outputFile\n";
}

function processCsv($inputFile, $outputFile) {
    global $PREFIX_MAP;
    
    if (!file_exists($inputFile)) {
        echo "❌ Fichier d'entrée non trouvé: $inputFile\n";
        exit(1);
    }
    
    $results = [];
    $headers = [];
    
    // Read and parse CSV
    $handle = fopen($inputFile, 'r');
    if ($handle === false) {
        echo "❌ Impossible d'ouvrir le fichier: $inputFile\n";
        exit(1);
    }
    
    // Read headers
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        echo "❌ Impossible de lire les en-têtes du fichier CSV\n";
        fclose($handle);
        exit(1);
    }
    
    // Process each row
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        // Ensure we have values for all headers, padding with empty strings if needed
        $fullRow = [];
        for ($i = 0; $i < count($headers); $i++) {
            $fullRow[] = isset($row[$i]) ? trim($row[$i]) : '';
        }
        
        $pathArray = array_filter($fullRow, function($v) { return $v !== ''; });
        
        // Skip if no data
        if (count($pathArray) === 0) continue;
        
        // Validate: no empty columns in the middle
        $lastIdx = -1;
        for ($i = 1; $i < count($fullRow); $i++) {
            if ($fullRow[$i] === '' && $fullRow[$i-1] !== '') {
                $lastIdx = $i;
                break;
            }
        }
        
        $isValid = $lastIdx === -1 || array_reduce(
            array_slice($fullRow, $lastIdx), 
            function($carry, $item) { return $carry && $item === ''; }, 
            true
        );
        
        if (!$isValid) continue;
        
        $typeName = $headers[count($pathArray) - 1];
        $typeSlug = slugify($typeName, "-", true);
        
        if (!isset($PREFIX_MAP[$typeSlug])) {
            echo "❌ Type de division inconnu: '$typeSlug'. Veuillez mettre à jour le tableau de correspondance.\n";
            exit(1);
        }
        
        $prefix = $PREFIX_MAP[$typeSlug];
        $divisionData = generateDivisionId($prefix, $pathArray, '-');
        
        // Calculate parent_id
        $parentId = null;
        if (count($pathArray) > 1) {
            $parentPathArray = array_slice($pathArray, 0, -1);
            $parentTypeName = $headers[count($parentPathArray) - 1];
            $parentTypeSlug = slugify($parentTypeName, "-", true);
            $parentPrefix = $PREFIX_MAP[$parentTypeSlug];
            $parentDivisionData = generateDivisionId($parentPrefix, $parentPathArray, '-');
            $parentId = $parentDivisionData['division_id'];
        }
        
        // Create enriched row
        $enriched = array_combine($headers, $fullRow);
        $enriched['parent_id'] = $parentId;
        $enriched['division_id'] = $divisionData['division_id'];
        $enriched['division_slug'] = $divisionData['division_slug'];
        
        $results[] = $enriched;
    }
    
    fclose($handle);
    
    if (count($results) === 0) {
        echo "⚠️ Aucun enregistrement valide trouvé.\n";
        return;
    }
    
    // Determine the maximum depth used in the data
    $maxDepth = 0;
    foreach ($results as $row) {
        $fullRow = [];
        foreach ($headers as $header) {
            $fullRow[] = trim($row[$header] ?? '');
        }
        $pathArray = array_filter($fullRow, function($v) { return $v !== ''; });
        $maxDepth = max($maxDepth, count($pathArray));
    }
    
    // Only include headers up to the maximum depth used + division fields
    $usedHeaders = array_slice($headers, 0, $maxDepth);
    $extendedHeaders = array_merge($usedHeaders, ['parent_id', 'division_id', 'division_slug']);
    
    // Write CSV output
    $outputHandle = fopen($outputFile, 'w');
    if ($outputHandle === false) {
        echo "❌ Impossible de créer le fichier de sortie: $outputFile\n";
        exit(1);
    }
    
    // Write headers
    fputcsv($outputHandle, $extendedHeaders, ',', '"', '\\');
    
    // Write data rows
    foreach ($results as $row) {
        $outputRow = [];
        foreach ($extendedHeaders as $header) {
            $outputRow[] = $row[$header] ?? '';
        }
        fputcsv($outputHandle, $outputRow, ',', '"', '\\');
    }
    
    fclose($outputHandle);
    echo "✅ Fichier généré : $outputFile\n";
}

// Exécution directe
if (php_sapi_name() === 'cli') {
    // Check for SQL mode
    if (in_array('--sql', $argv)) {
        $sqlIndex = array_search('--sql', $argv);
        $inputFile = $argv[$sqlIndex + 1] ?? "data.csv";
        $outputFile = $argv[$sqlIndex + 2] ?? "inserts.sql";
        
        generateSqlInserts($inputFile, $outputFile);
    } else {
        // CSV mode
        $inputFile = $argv[1] ?? "data.csv";
        $outputFile = $argv[2] ?? "output.csv";
        
        processCsv($inputFile, $outputFile);
    }
}