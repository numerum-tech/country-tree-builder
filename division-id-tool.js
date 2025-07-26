// division_id_tool.js — Module Node.js autonome et exécutable
// Aucune dépendance externe requise - utilise uniquement les modules standard Node.js

const fs = require("fs");
const crypto = require("crypto");

// Custom slugify function to match Python behavior
function slugify(text, separator = "-", lowercase = true) {
    // Simple implementation to match Python unicodedata.normalize('NFD')
    text = text.normalize('NFD');
    // Remove accents (combining characters)
    text = text.replace(/[\u0300-\u036f]/g, '');

    if (lowercase) {
        text = text.toLowerCase();
    }

    // Replace non-alphanumeric characters with separator
    text = text.replace(/[^a-zA-Z0-9]+/g, separator);

    // Remove leading/trailing separators
    text = text.replace(new RegExp(`^${separator}+|${separator}+$`, 'g'), '');

    return text;
}

// Custom CSV parser
function parseCSV(filePath) {
    return new Promise((resolve, reject) => {
        fs.readFile(filePath, 'utf8', (err, data) => {
            if (err) {
                reject(err);
                return;
            }

            const lines = data.trim().split('\n');
            if (lines.length === 0) {
                resolve({ headers: [], rows: [] });
                return;
            }

            // Parse headers from first line
            const headers = lines[0].split(',').map(header => header.trim().replace(/^"|"$/g, ''));

            // Parse data rows
            const rows = [];
            for (let i = 1; i < lines.length; i++) {
                const line = lines[i].trim();
                if (!line) continue;

                // Split line by comma, but ensure we have the right number of columns
                const values = line.split(',');
                const row = {};

                // Ensure we have values for all headers, padding with empty strings if needed
                for (let j = 0; j < headers.length; j++) {
                    const value = (values[j] || '').trim().replace(/^"|"$/g, '');
                    row[headers[j]] = value;
                }

                rows.push(row);
            }

            resolve({ headers, rows });
        });
    });
}

// Custom CSV writer
function writeCSV(filePath, headers, rows) {
    return new Promise((resolve, reject) => {
        try {
            let csvContent = headers.join(',') + '\r\n';

            rows.forEach(row => {
                const values = headers.map(header => {
                    const value = row[header] || '';
                    // Escape values containing commas or quotes
                    if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                        return `"${value.replace(/"/g, '""')}"`;
                    }
                    return value;
                });
                csvContent += values.join(',') + '\r\n';
            });

            fs.writeFile(filePath, csvContent, 'utf8', (err) => {
                if (err) {
                    reject(err);
                } else {
                    resolve();
                }
            });
        } catch (error) {
            reject(error);
        }
    });
}

// Préfixes recommandés basés sur les slugs de type (adapté pour francophonie)
const prefixMap = {
    "pays": "PA",
    "region": "RG",
    "prefecture": "PR",
    "province": "PV",
    "departement": "DP",
    "district": "DI",
    "arrondissement": "AR",
    "commune": "CM",
    "ville": "VL",
    "quartier": "QR",
    "localite": "LC",
    "secteur-villageois": "SV",
    "village": "VG",
    "zone-sanitaire": "ZS",
    "zone-de-developpement": "ZD",
    "zone-electorale": "ZE",
    "canton": "CC",
    "territoire": "TR",
    "metropole": "MT"
};

function normalizedSlug(pathArray) {
    return pathArray
        .map(name => slugify(name, "-", true))
        .join(".");
}

function rfc4648Base32Encode(buffer) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let result = '';
    let bits = 0;
    let value = 0;

    for (let i = 0; i < buffer.length; i++) {
        value = (value << 8) | buffer[i];
        bits += 8;

        while (bits >= 5) {
            result += alphabet[(value >>> (bits - 5)) & 31];
            bits -= 5;
        }
    }

    if (bits > 0) {
        result += alphabet[(value << (5 - bits)) & 31];
    }

    return result;
}

function generateDivisionId(typePrefix, pathArray, prefixDelimiter = '-') {
    const slug = normalizedSlug(pathArray);
    const hash = crypto.createHash("sha256").update(slug).digest();
    const base32 = rfc4648Base32Encode(hash);
    return {
        division_id: `${typePrefix}${prefixDelimiter}${base32.slice(0, 6)}`,
        division_slug: slug
    };
}

function generateSqlInserts(inputFile, outputFile) {
    return new Promise(async (resolve, reject) => {
        try {
            const { headers, rows } = await parseCSV(inputFile);
            
            // Generate division types from headers
            const divisionTypes = [];
            const divisionTypeMap = new Map(); // Maps type name to type info
            let typeIdCounter = 1;
            
            headers.forEach((headerName, level) => {
                const typeSlug = slugify(headerName, "-", true);
                const prefix = prefixMap[typeSlug];
                
                if (!prefix) {
                    throw new Error(`Type de division inconnu: '${typeSlug}'. Veuillez mettre à jour le tableau de correspondance.`);
                }
                
                const parentTypeId = level > 0 ? level : null; // Parent is previous level, null for first level
                
                const typeInfo = {
                    id: typeIdCounter,
                    name: headerName,
                    code: prefix,
                    level: level,
                    parent_type_id: parentTypeId,
                    description: `Type de division: ${headerName}`
                };
                
                divisionTypes.push(typeInfo);
                divisionTypeMap.set(headerName, typeInfo);
                typeIdCounter++;
            });
            
            // Generate divisions with parent relationships
            const divisions = [];
            const divisionMap = new Map(); // Maps division_id to division info
            
            for (const row of rows) {
                const fullRow = headers.map(h => (row[h] || "").trim());
                const pathArray = fullRow.filter(v => v);
                
                // Skip if no data
                if (pathArray.length === 0) continue;
                
                // Validate: no empty columns in the middle
                const lastIdx = fullRow.findIndex((val, i) => val === "" && fullRow[i - 1] !== "");
                const isValid = lastIdx === -1 || fullRow.slice(lastIdx).every(v => v === "");
                if (!isValid) continue;
                
                const typeName = headers[pathArray.length - 1];
                const typeSlug = slugify(typeName, "-", true);
                const prefix = prefixMap[typeSlug];
                
                const { division_id, division_slug } = generateDivisionId(prefix, pathArray, '-');
                
                // Calculate parent_id by getting the division_id of the first n-1 elements
                let parentId = null;
                if (pathArray.length > 1) {
                    const parentPathArray = pathArray.slice(0, -1);
                    const parentTypeName = headers[parentPathArray.length - 1];
                    const parentTypeSlug = slugify(parentTypeName, "-", true);
                    const parentPrefix = prefixMap[parentTypeSlug];
                    const parentDivisionInfo = generateDivisionId(parentPrefix, parentPathArray, '-');
                    
                    parentId = parentDivisionInfo.division_id;
                }
                
                const typeInfo = divisionTypeMap.get(typeName);
                
                const division = {
                    division_id: division_id,
                    type_id: typeInfo.id,
                    parent_id: parentId,
                    name: pathArray[pathArray.length - 1], // Last element is the name
                    division_slug: division_slug
                };
                
                divisions.push(division);
                divisionMap.set(division_id, division);
            }
            
            // Generate SQL
            let sql = "-- SQL Insert Script for Country Tree Structure\n";
            sql += "-- Generated by division-id-tool.js\n\n";
            
            // Insert division types
            sql += "-- Insert division types\n";
            for (const type of divisionTypes) {
                sql += `INSERT INTO country_division_types (id, name, code, level, parent_type_id, description) VALUES (${type.id}, '${type.name.replace(/'/g, "''")}', '${type.code}', ${type.level}, ${type.parent_type_id}, '${type.description.replace(/'/g, "''")}');\n`;
            }
            
            sql += "\n-- Insert divisions\n";
            for (const division of divisions) {
                const parentIdValue = division.parent_id ? `'${division.parent_id}'` : 'NULL';
                sql += `INSERT INTO country_divisions (id, type_id, parent_id, name, division_slug) VALUES ('${division.division_id}', ${division.type_id}, ${parentIdValue}, '${division.name.replace(/'/g, "''")}', '${division.division_slug}');\n`;
            }
            
            // Write SQL to file
            fs.writeFile(outputFile, sql, 'utf8', (err) => {
                if (err) {
                    reject(err);
                } else {
                    console.log(`✅ Script SQL généré : ${outputFile}`);
                    resolve();
                }
            });
            
        } catch (error) {
            reject(error);
        }
    });
}

async function processCsv(inputFile, outputFile) {
    try {
        const { headers: headerFields, rows } = await parseCSV(inputFile);
        const results = [];

        for (const row of rows) {
            const fullRow = headerFields.map(f => (row[f] || "").trim());
            const pathArray = fullRow.filter(v => v);

            // Skip if no data
            if (pathArray.length === 0) continue;

            // Validate: no empty columns in the middle
            const lastIdx = fullRow.findIndex((val, i) => val === "" && fullRow[i - 1] !== "");
            const isValid = lastIdx === -1 || fullRow.slice(lastIdx).every(v => v === "");
            if (!isValid) continue;

            const typeName = headerFields[pathArray.length - 1];
            const typeSlug = slugify(typeName, "-", true);
            const prefix = prefixMap[typeSlug];

            if (!prefix) {
                console.error(`❌ Type de division inconnu: '${typeSlug}'. Veuillez mettre à jour le tableau de correspondance.`);
                process.exit(1);
            }

            const { division_id, division_slug } = generateDivisionId(prefix, pathArray, '-');
            
            // Calculate parent_id
            let parent_id = null;
            if (pathArray.length > 1) {
                const parentPathArray = pathArray.slice(0, -1);
                const parentTypeName = headerFields[parentPathArray.length - 1];
                const parentTypeSlug = slugify(parentTypeName, "-", true);
                const parentPrefix = prefixMap[parentTypeSlug];
                const parentDivisionInfo = generateDivisionId(parentPrefix, parentPathArray, '-');
                parent_id = parentDivisionInfo.division_id;
            }
            
            const enriched = { ...row, parent_id, division_id, division_slug };
            results.push(enriched);
        }

        if (results.length === 0) {
            console.warn("⚠️ Aucun enregistrement valide trouvé.");
            return;
        }

        // Determine the maximum depth used in the data
        const maxDepth = Math.max(...results.map(row => {
            const fullRow = headerFields.map(f => (row[f] || "").trim());
            return fullRow.filter(v => v).length;
        }));

        // Only include headers up to the maximum depth used + division fields
        const usedHeaders = headerFields.slice(0, maxDepth);
        const extendedHeaderFields = [...usedHeaders, 'parent_id', 'division_id', 'division_slug'];

        await writeCSV(outputFile, extendedHeaderFields, results);
        console.log(`✅ Fichier généré : ${outputFile}`);

    } catch (error) {
        console.error(`❌ Erreur lors du traitement : ${error.message}`);
        process.exit(1);
    }
}

// Exécution directe
if (require.main === module) {
    const args = process.argv.slice(2);
    
    if (args.includes('--sql')) {
        // SQL mode: generate SQL insert script
        const sqlIndex = args.indexOf('--sql');
        const inputFile = args[sqlIndex + 1] || "data.csv";
        const outputFile = args[sqlIndex + 2] || "inserts.sql";
        
        generateSqlInserts(inputFile, outputFile).catch(error => {
            console.error(`❌ Erreur lors de la génération SQL : ${error.message}`);
            process.exit(1);
        });
    } else {
        // CSV mode: generate enriched CSV
        const inputFile = args[0] || "data.csv";
        const outputFile = args[1] || "output.csv";
        processCsv(inputFile, outputFile);
    }
}

// Exportation possible pour usage externe
module.exports = {
    generateDivisionId,
    processCsv,
    generateSqlInserts
};
