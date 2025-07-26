# ==============================
# division_id_tool.py — Version Python adaptée multi-niveaux
# Aucune dépendance externe requise - utilise uniquement les modules standard Python
# ==============================

import hashlib
import csv
import base64
import sys
import re
import unicodedata

# Préfixes typologiques francophones
PREFIX_MAP = {
    "pays": "PA", "region": "RG", "prefecture": "PR", "province": "PV",
    "departement": "DP", "district": "DI", "arrondissement": "AR", "commune": "CM",
    "ville": "VL", "quartier": "QR", "localite": "LC", "secteur-villageois": "SV",
    "village": "VG", "zone-sanitaire": "ZS", "zone-de-developpement": "ZD",
    "zone-electorale": "ZE", "canton": "CC", "territoire": "TR", "metropole": "MT"
}

def slugify(text, separator="-", lowercase=True):
    """Simple slugify function to match JavaScript slugify behavior"""
    # Convert to NFD (decomposed) form and remove accents
    text = unicodedata.normalize('NFD', text)
    text = ''.join(char for char in text if unicodedata.category(char) != 'Mn')
    
    if lowercase:
        text = text.lower()
    
    # Replace non-alphanumeric characters with separator
    text = re.sub(r'[^a-zA-Z0-9]+', separator, text)
    
    # Remove leading/trailing separators
    text = text.strip(separator)
    
    return text

def normalized_slug(path):
    return ".".join([slugify(name, separator="-", lowercase=True) for name in path])

def generate_division_id(prefix, path, prefix_delimiter=''):
    slug = normalized_slug(path)
    digest = hashlib.sha256(slug.encode()).digest()
    base32_id = base64.b32encode(digest).decode("utf-8").upper().rstrip("=")
    return f"{prefix}{prefix_delimiter}{base32_id[:6]}", slug

def generate_sql_inserts(input_file, output_file):
    """Generate SQL insert statements compatible with the database schema"""
    with open(input_file, 'r', encoding='utf-8') as csvfile:
        reader = csv.DictReader(csvfile)
        headers = reader.fieldnames
        
        # Generate division types from headers
        division_types = []
        division_type_map = {}
        type_id_counter = 1
        
        for level, header_name in enumerate(headers):
            type_slug = slugify(header_name, separator="-", lowercase=True)
            prefix = PREFIX_MAP.get(type_slug)
            
            if not prefix:
                print(f"❌ Type de division inconnu: '{type_slug}'. Veuillez mettre à jour le tableau de correspondance.")
                sys.exit(1)
            
            parent_type_id = level if level > 0 else 'null'
            
            type_info = {
                'id': type_id_counter,
                'name': header_name,
                'code': prefix,
                'level': level,
                'parent_type_id': parent_type_id,
                'description': f"Type de division: {header_name}"
            }
            
            division_types.append(type_info)
            division_type_map[header_name] = type_info
            type_id_counter += 1
        
        # Generate divisions with parent relationships
        divisions = []
        division_map = {}
        
        csvfile.seek(0)  # Reset to beginning
        next(reader)  # Skip header
        
        for row in reader:
            full_row = [str(row.get(col, "") or "").strip() for col in headers]
            path_array = [v for v in full_row if v != ""]
            
            # Skip if no data
            if len(path_array) == 0:
                continue
            
            # Validate: no empty columns in the middle
            last_idx = -1
            for i in range(1, len(full_row)):
                if full_row[i] == "" and full_row[i-1] != "":
                    last_idx = i
                    break
            
            is_valid = last_idx == -1 or all(v == "" for v in full_row[last_idx:])
            if not is_valid:
                continue
            
            type_name = headers[len(path_array)-1]
            type_slug = slugify(type_name, separator="-", lowercase=True)
            prefix = PREFIX_MAP.get(type_slug)
            
            division_id, division_slug = generate_division_id(prefix, path_array, '-')
            
            # Calculate parent_id by getting the division_id of the first n-1 elements
            parent_id = None
            if len(path_array) > 1:
                parent_path_array = path_array[:-1]
                parent_type_name = headers[len(parent_path_array)-1]
                parent_type_slug = slugify(parent_type_name, separator="-", lowercase=True)
                parent_prefix = PREFIX_MAP.get(parent_type_slug)
                parent_division_id, _ = generate_division_id(parent_prefix, parent_path_array, '-')
                parent_id = parent_division_id
            
            type_info = division_type_map[type_name]
            
            division = {
                'id': division_id,
                'type_id': type_info['id'],
                'parent_id': parent_id,
                'name': path_array[-1],  # Last element is the name
                'division_slug': division_slug
            }
            
            divisions.append(division)
            division_map[division_id] = division
    
    # Generate SQL
    sql = "-- SQL Insert Script for Country Tree Structure\n"
    sql += "-- Generated by division-id-tool.py\n\n"
    
    # Insert division types
    sql += "-- Insert division types\n"
    for type_info in division_types:
        name_escaped = type_info['name'].replace("'", "''")
        description_escaped = type_info['description'].replace("'", "''")
        sql += f"INSERT INTO country_division_types (id, name, code, level, parent_type_id, description) VALUES "
        sql += f"({type_info['id']}, '{name_escaped}', '{type_info['code']}', "
        sql += f"{type_info['level']}, {type_info['parent_type_id']}, '{description_escaped}');\n"
    
    sql += "\n-- Insert divisions\n"
    for division in divisions:
        parent_id_value = f"'{division['parent_id']}'" if division['parent_id'] else 'NULL'
        name_escaped = division['name'].replace("'", "''")
        sql += f"INSERT INTO country_divisions (id, type_id, parent_id, name, division_slug) VALUES "
        sql += f"('{division['id']}', {division['type_id']}, {parent_id_value}, "
        sql += f"'{name_escaped}', '{division['division_slug']}');\n"
    
    # Write SQL to file
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(sql)
    
    print(f"✅ Script SQL généré : {output_file}")

def process_csv(input_file, output_file):
    enriched_rows = []
    headers = []
    
    with open(input_file, 'r', encoding='utf-8') as csvfile:
        reader = csv.DictReader(csvfile)
        headers = reader.fieldnames
        
        for row in reader:
            full_row = [str(row.get(col, "") or "").strip() for col in headers]
            path_array = [v for v in full_row if v != ""]
            
            # Skip if no data
            if len(path_array) == 0:
                continue

            # Validate: no empty columns in the middle
            last_idx = -1
            for i in range(1, len(full_row)):
                if full_row[i] == "" and full_row[i-1] != "":
                    last_idx = i
                    break
            
            is_valid = last_idx == -1 or all(v == "" for v in full_row[last_idx:])
            if not is_valid:
                continue
                
            type_name = headers[len(path_array)-1]
            type_slug = slugify(type_name, separator="-", lowercase=True)
            prefix = PREFIX_MAP.get(type_slug)
            if not prefix:
                print(f"❌ Type de division inconnu: '{type_slug}'. Veuillez mettre à jour le tableau de correspondance.")
                sys.exit(1)

            division_id, slug = generate_division_id(prefix, path_array, '-')
            
            # Calculate parent_id
            parent_id = None
            if len(path_array) > 1:
                parent_path_array = path_array[:-1]
                parent_type_name = headers[len(parent_path_array)-1]
                parent_type_slug = slugify(parent_type_name, separator="-", lowercase=True)
                parent_prefix = PREFIX_MAP.get(parent_type_slug)
                parent_division_id, _ = generate_division_id(parent_prefix, parent_path_array, '-')
                parent_id = parent_division_id
            
            enriched = dict(row)
            enriched["parent_id"] = parent_id
            enriched["division_id"] = division_id
            enriched["division_slug"] = slug
            enriched_rows.append(enriched)

    if enriched_rows:
        # Determine the maximum depth used in the data
        max_depth = max(len([v for v in [str(row.get(col, "")).strip() for col in headers] if v != ""]) 
                        for row in enriched_rows)
        
        # Only include headers up to the maximum depth used + division fields
        used_headers = headers[:max_depth]
        extended_headers = used_headers + ['parent_id', 'division_id', 'division_slug']
        
        # Write CSV output
        with open(output_file, 'w', encoding='utf-8', newline='') as csvfile:
            writer = csv.DictWriter(csvfile, fieldnames=extended_headers)
            writer.writeheader()
            writer.writerows(enriched_rows)
            
        print(f"✅ Fichier généré : {output_file}")
    else:
        print("⚠️ Aucun enregistrement valide trouvé.")

if __name__ == "__main__":
    # Check for SQL mode
    if '--sql' in sys.argv:
        sql_index = sys.argv.index('--sql')
        infile = sys.argv[sql_index + 1] if len(sys.argv) > sql_index + 1 else "data.csv"
        outfile = sys.argv[sql_index + 2] if len(sys.argv) > sql_index + 2 else "inserts.sql"
        generate_sql_inserts(infile, outfile)
    else:
        # CSV mode
        infile = sys.argv[1] if len(sys.argv) > 1 else "data.csv"
        outfile = sys.argv[2] if len(sys.argv) > 2 else "output.csv"
        process_csv(infile, outfile)