#!/usr/bin/env python3
"""
Generates database/agrimatch_db.sql — a single phpMyAdmin-ready MySQL/MariaDB dump
for the AgriMatch PH B2B Agricultural Supply Matching & Price Index Platform.

Deterministic (fixed random seed) so the dump is reproducible.
Run:  python3 database/generate_seed.py
"""
import hashlib
import json
import math
import random
from datetime import date, datetime, timedelta
from pathlib import Path

random.seed(20261030)

TODAY = date(2026, 10, 30)
NOW = datetime(2026, 10, 30, 8, 0, 0)
PRICE_HISTORY_DAYS = 120
# bcrypt hash of the demo password "password" (cost 12, Laravel-compatible)
PASSWORD_HASH = "$2y$12$QmEW0LO3vM2vxsVZPwb7zunASrrZpLPXuPTs2cdVmerVAbTmqAv5S"

OUT = Path(__file__).with_name("agrimatch_db.sql")


def q(v):
    """SQL literal."""
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int,)):
        return str(v)
    if isinstance(v, float):
        return f"{v:.2f}"
    if isinstance(v, datetime):
        return "'" + v.strftime("%Y-%m-%d %H:%M:%S") + "'"
    if isinstance(v, date):
        return "'" + v.isoformat() + "'"
    s = str(v).replace("\\", "\\\\").replace("'", "''")
    return f"'{s}'"


def insert(table, cols, rows, chunk=400):
    out = []
    for i in range(0, len(rows), chunk):
        part = rows[i:i + chunk]
        vals = ",\n".join("(" + ", ".join(q(v) for v in r) + ")" for r in part)
        out.append(f"INSERT INTO `{table}` (" + ", ".join(f"`{c}`" for c in cols) + ") VALUES\n" + vals + ";\n")
    return "\n".join(out)


def ts(days_ago=0, hour=8, minute=0):
    return NOW.replace(hour=hour, minute=minute) - timedelta(days=days_ago)


# ---------------------------------------------------------------------------
# Reference data
# ---------------------------------------------------------------------------
PROVINCES = [
    # id, name, region, region_code, lat, lng
    (1, "Bukidnon", "Northern Mindanao", "X", 8.0515, 124.9230),
    (2, "Misamis Oriental", "Northern Mindanao", "X", 8.5046, 124.6220),
    (3, "Davao del Sur", "Davao Region", "XI", 6.7663, 125.3284),
    (4, "Davao del Norte", "Davao Region", "XI", 7.5619, 125.6533),
    (5, "Davao de Oro", "Davao Region", "XI", 7.3172, 126.1748),
    (6, "South Cotabato", "SOCCSKSARGEN", "XII", 6.2969, 124.8511),
    (7, "Lanao del Norte", "Northern Mindanao", "X", 7.8722, 123.8858),
    (8, "Agusan del Norte", "Caraga", "XIII", 8.9456, 125.5319),
    (9, "Benguet", "Cordillera Administrative Region", "CAR", 16.4023, 120.5960),
    (10, "Nueva Ecija", "Central Luzon", "III", 15.5784, 120.9726),
    (11, "Cebu", "Central Visayas", "VII", 10.3157, 123.8854),
    (12, "Camiguin", "Northern Mindanao", "X", 9.1732, 124.7299),
    (13, "Metro Manila", "National Capital Region", "NCR", 14.5995, 120.9842),
]

CATEGORIES = [
    # id, name, slug, description, icon, color
    (1, "Alliums & Aromatics", "alliums-aromatics", "Onions, garlic, ginger and other aromatic bulbs and rhizomes.", "sprout", "#b45309"),
    (2, "Lowland Vegetables", "lowland-vegetables", "Fruit vegetables grown in warm lowland farms — tomato, eggplant, pepper.", "leaf", "#15803d"),
    (3, "Highland Vegetables", "highland-vegetables", "Cool-climate crops from upland farms in Bukidnon and Benguet.", "mountain", "#0f766e"),
    (4, "Fruits", "fruits", "Export and domestic fruit crops — banana, mango, pineapple, citrus.", "apple", "#ca8a04"),
    (5, "Grains & Cereals", "grains-cereals", "Palay, white and yellow corn for milling and feed.", "wheat", "#a16207"),
    (6, "Root Crops", "root-crops", "Sweet potato, cassava and other tubers.", "carrot", "#9a3412"),
]

# id, cat, name, slug, local_name, scientific, shelf_life_days, season, base_farmgate, volatility, provinces(where grown)
VARIETIES = [
    (1, 1, "Red Onion (Red Pinoy)", "red-onion", "Sibuyas Pula", "Allium cepa", 90, "Feb–May harvest; stored year-round", 110.0, 0.022, [10, 1, 11]),
    (2, 1, "Yellow Granex Onion", "yellow-granex-onion", "Sibuyas Puti", "Allium cepa", 75, "Feb–Apr harvest", 95.0, 0.020, [10, 1]),
    (3, 1, "Native Garlic", "native-garlic", "Bawang", "Allium sativum", 150, "Mar–Apr harvest", 165.0, 0.012, [1, 10]),
    (4, 1, "Ginger (Native)", "ginger-native", "Luya", "Zingiber officinale", 60, "Year-round; peak Oct–Jan", 72.0, 0.018, [1, 2, 3, 6]),
    (5, 2, "Lowland Tomato (Diamante Max)", "lowland-tomato", "Kamatis", "Solanum lycopersicum", 14, "Year-round; glut Jan–Mar", 45.0, 0.035, [2, 1, 3, 6, 11]),
    (6, 2, "Eggplant (Long Purple)", "eggplant-long-purple", "Talong", "Solanum melongena", 10, "Year-round", 42.0, 0.028, [2, 3, 7, 11]),
    (7, 2, "Ampalaya (Bitter Gourd)", "ampalaya", "Ampalaya", "Momordica charantia", 10, "Year-round", 58.0, 0.025, [2, 3, 7]),
    (8, 3, "Highland Cabbage (Scorpio)", "highland-cabbage", "Repolyo", "Brassica oleracea", 21, "Year-round; peak Nov–Feb", 38.0, 0.030, [1, 9]),
    (9, 3, "Carrots (Kuroda)", "carrots-kuroda", "Karot", "Daucus carota", 30, "Year-round", 55.0, 0.022, [1, 9]),
    (10, 3, "White Potato (Granola)", "white-potato-granola", "Patatas", "Solanum tuberosum", 60, "Nov–Mar main crop", 62.0, 0.015, [1, 9]),
    (11, 4, "Cavendish Banana", "cavendish-banana", "Saging Cavendish", "Musa acuminata (AAA)", 21, "Year-round export crop", 22.0, 0.010, [4, 5, 3, 1]),
    (12, 4, "Lakatan Banana", "lakatan-banana", "Lakatan", "Musa acuminata (AAA)", 10, "Year-round", 55.0, 0.016, [3, 4, 2, 8]),
    (13, 4, "Saba Banana", "saba-banana", "Saging Saba", "Musa × paradisiaca (ABB)", 12, "Year-round", 26.0, 0.014, [2, 7, 8, 1]),
    (14, 4, "Carabao Mango", "carabao-mango", "Mangga Kalabaw", "Mangifera indica", 9, "Mar–Jun peak; off-season induced", 78.0, 0.024, [11, 3, 6]),
    (15, 4, "Pineapple (MD-2)", "pineapple-md2", "Pinya", "Ananas comosus", 14, "Year-round", 30.0, 0.012, [1, 6, 12]),
    (16, 4, "Calamansi", "calamansi", "Kalamansi", "Citrus × microcarpa", 14, "Aug–Dec peak", 48.0, 0.026, [2, 12, 7]),
    (17, 5, "White Corn (Grain)", "white-corn", "Maisan Puti", "Zea mays", 180, "Two crops per year", 18.5, 0.008, [1, 7, 6]),
    (18, 5, "Yellow Corn (Feed Grade)", "yellow-corn", "Maisan Dilaw", "Zea mays", 180, "Two crops per year", 17.0, 0.008, [1, 6, 7]),
    (19, 5, "Palay (NSIC Rc 222)", "palay-rc222", "Palay", "Oryza sativa", 365, "Wet & dry season harvests", 21.0, 0.007, [10, 6, 1, 4]),
    (20, 6, "Sweet Potato (Kamote)", "sweet-potato", "Kamote", "Ipomoea batatas", 30, "Year-round", 32.0, 0.018, [1, 2, 7]),
]
VAR_BY_ID = {v[0]: v for v in VARIETIES}
PROV_BY_ID = {p[0]: p for p in PROVINCES}

# Provincial price differentials (multiplier on base farmgate) — remoteness & local supply.
PROV_DIFF = {1: 0.94, 2: 1.03, 3: 1.05, 4: 0.97, 5: 0.98, 6: 0.96, 7: 0.99, 8: 1.02, 9: 0.92, 10: 0.95, 11: 1.08, 12: 1.04, 13: 1.15}

# ---------------------------------------------------------------------------
# Users (role, name, email, phone, verified) + profiles
# ---------------------------------------------------------------------------
USERS = [
    # id, role, name, email, phone, is_verified, status
    (1, "admin", "AgriMatch Administrator", "admin@agrimatch.test", "+63 917 000 0001", True, "active"),
    # Farmers
    (2, "farmer", "Romeo Dagatan", "romeo.dagatan@agrimatch.test", "+63 917 812 4410", True, "active"),
    (3, "farmer", "Lorna Bacarro", "lorna.bacarro@agrimatch.test", "+63 918 225 9031", True, "active"),
    (4, "coop_manager", "Datu Ramil Manggubat", "ramil.manggubat@agrimatch.test", "+63 926 441 7782", True, "active"),
    (5, "farmer", "Josefina Lumbay", "josefina.lumbay@agrimatch.test", "+63 935 610 2290", True, "active"),
    (6, "farmer", "Ernesto Pagapulaan", "ernesto.pagapulaan@agrimatch.test", "+63 908 774 1155", False, "active"),
    (7, "farmer", "Marites Calingasan", "marites.calingasan@agrimatch.test", "+63 977 302 6648", True, "active"),
    (8, "farmer", "Benjie Taguinod", "benjie.taguinod@agrimatch.test", "+63 919 556 0187", True, "active"),
    (9, "farmer", "Arnel Sumampong", "arnel.sumampong@agrimatch.test", "+63 921 883 4402", False, "active"),
    (10, "farmer", "Gemma Paypa", "gemma.paypa@agrimatch.test", "+63 945 128 7739", True, "active"),
    (11, "coop_manager", "Norberto Ambos", "norberto.ambos@agrimatch.test", "+63 956 774 3021", True, "active"),
    # Traders / consolidators
    (12, "trader", "Kenneth Uy Lim", "kenneth.lim@agrimatch.test", "+63 917 503 8812", True, "active"),
    (13, "trader", "Rosalinda Ebarle", "rosalinda.ebarle@agrimatch.test", "+63 918 662 0941", True, "active"),
    (14, "trader", "Hadji Omar Pangandaman", "omar.pangandaman@agrimatch.test", "+63 927 115 6630", True, "active"),
    (15, "trader", "Christian Velez", "christian.velez@agrimatch.test", "+63 939 448 2276", False, "active"),
    # Buyers (institutional)
    (16, "buyer", "Patricia Go", "procurement@gaisanofresh.test", "+63 88 856 1200", True, "active"),
    (17, "buyer", "Miguel Abellana", "miguel.abellana@cdokitchens.test", "+63 917 220 4488", True, "active"),
    (18, "buyer", "Ana Liza Fuentes", "analiza.fuentes@mindanaofoodpack.test", "+63 82 297 5510", True, "active"),
    (19, "buyer", "Rafael Dimaporo", "rafael.dimaporo@iligangrocers.test", "+63 63 221 3307", True, "active"),
    (20, "buyer", "Sheila Montecillo", "sheila.montecillo@visayasfoodhub.test", "+63 32 412 8890", True, "active"),
    (21, "buyer", "Jerome Salvaña", "jerome.salvana@davaoexportco.test", "+63 82 300 7741", False, "active"),
    # Smallholder cooperative members (pool contributors)
    (22, "farmer", "Rodel Lagumbay", "rodel.lagumbay@agrimatch.test", "+63 916 330 2214", True, "active"),
    (23, "farmer", "Nenita Bayawa", "nenita.bayawa@agrimatch.test", "+63 938 417 9902", True, "active"),
    (24, "farmer", "Jonard Pabelic", "jonard.pabelic@agrimatch.test", "+63 995 208 1376", True, "active"),
    (25, "farmer", "Felicidad Ompad", "felicidad.ompad@agrimatch.test", "+63 907 552 4180", False, "active"),
    (26, "farmer", "Teodoro Sabanal", "teodoro.sabanal@agrimatch.test", "+63 929 761 0043", True, "active"),
    (27, "farmer", "Rowena Lagrosas", "rowena.lagrosas@agrimatch.test", "+63 948 120 6657", True, "active"),
    # Quality technicians (BPI / coop inspection desk)
    (28, "quality_inspector", "Engr. Mylene Tabañag", "mylene.tabanag@agrimatch.test", "+63 917 640 3381", True, "active"),
    (29, "quality_inspector", "Alvin Rosales", "alvin.rosales@agrimatch.test", "+63 918 902 7714", True, "active"),
    # Truck drivers / haulers
    (30, "driver", "Junrey Abellanosa", "junrey.abellanosa@agrimatch.test", "+63 926 305 1128", True, "active"),
    (31, "driver", "Ricky Dumdum", "ricky.dumdum@agrimatch.test", "+63 965 774 2093", True, "active"),
]

# user_id, business_name, business_type, bio, province_id, municipality, barangay, farm_size_ha, monthly_capacity_kg, rating, total_tx
PROFILES = [
    (1, "AgriMatch PH Operations", "platform", "Platform operations and listing verification desk.", 2, "Cagayan de Oro City", "Carmen", None, None, 5.00, 0),
    (2, "Dagatan Highland Farms", "individual_farm", "Third-generation vegetable grower supplying cabbage, carrots and potato to CDO and Davao wholesalers.", 1, "Lantapan", "Songco", 6.50, 42000, 4.80, 37),
    (3, "Bacarro Family Tomato Farm", "individual_farm", "Lowland tomato and eggplant on drip irrigation; GAP-certified since 2023.", 2, "Claveria", "Patrocenio", 3.20, 18000, 4.65, 22),
    (4, "Manggubat Ancestral Farm Cooperative", "cooperative", "Talaandig farmers' cooperative growing ginger, sweet potato and highland vegetables.", 1, "Lantapan", "Kibangay", 28.00, 65000, 4.90, 51),
    (5, "Lumbay Agri Ventures", "individual_farm", "Pineapple and calamansi orchards with packhouse access.", 12, "Mambajao", "Yumbing", 9.00, 30000, 4.55, 16),
    (6, "Pagapulaan Corn & Palay", "individual_farm", "Rotational white corn and palay grower; mechanized harvesting.", 7, "Kapatagan", "Poblacion", 12.00, 80000, 4.20, 9),
    (7, "Calingasan Onion Growers", "individual_farm", "Red Pinoy and Yellow Granex onion grower with cold-storage partner in San Jose City.", 10, "Bongabon", "Labi", 7.50, 55000, 4.70, 28),
    (8, "Taguinod Banana Plantation", "corporate_farm", "Cavendish export-grade and lakatan bananas; Fusarium-resistant GCTCV clones.", 4, "Panabo City", "Cagangohan", 45.00, 260000, 4.85, 63),
    (9, "Sumampong Mixed Farm", "individual_farm", "Saba banana, ampalaya and kamote for Iligan and CDO markets.", 7, "Baloi", "Abaga", 4.00, 15000, 4.10, 6),
    (10, "Paypa Mango Orchard", "individual_farm", "Off-season induced Carabao mango; hot-water treated for export trials.", 6, "Tupi", "Linan", 11.00, 24000, 4.75, 19),
    (11, "Ambos Agrarian Reform Beneficiaries Coop", "cooperative", "ARBO consolidating yellow corn and palay from 140 member-farmers.", 1, "Maramag", "Base Camp", 210.00, 420000, 4.60, 44),
    (12, "Lim Agri Trading", "trading", "Northern Mindanao vegetable consolidator delivering to Cogon and Carmen markets.", 2, "Cagayan de Oro City", "Cogon", None, 150000, 4.70, 88),
    (13, "Ebarle Fresh Consolidators", "trading", "Davao-based fruit and vegetable consolidator with reefer trucks.", 3, "Davao City", "Bankerohan", None, 200000, 4.60, 71),
    (14, "Pangandaman Agri Supply", "trading", "Grain trader and warehouse operator serving Lanao and Bukidnon.", 7, "Iligan City", "Tibanga", None, 500000, 4.50, 54),
    (15, "Velez Produce Logistics", "trading", "Inter-island produce shipping between Mindanao and Cebu.", 11, "Cebu City", "Pasil", None, 120000, 4.30, 12),
    (16, "Gaisano Fresh Market Procurement", "supermarket", "Supermarket chain fresh-produce procurement, Northern Mindanao cluster.", 2, "Cagayan de Oro City", "Lapasan", None, 90000, 4.90, 40),
    (17, "CDO Central Kitchens Inc.", "food_service", "Commissary supplying 32 restaurant outlets across CDO and Iligan.", 2, "Cagayan de Oro City", "Kauswagan", None, 25000, 4.70, 24),
    (18, "Mindanao Food Packers Corp.", "processor", "Banana chips, dried mango and calamansi concentrate processor.", 3, "Davao City", "Toril", None, 180000, 4.80, 33),
    (19, "Iligan City Grocers Association", "wholesaler", "Bulk buying group for 60+ sari-sari and grocery retailers.", 7, "Iligan City", "Pala-o", None, 70000, 4.40, 15),
    (20, "Visayas Food Hub", "wholesaler", "Cebu wholesale distributor for hotels, restaurants and institutions.", 11, "Mandaue City", "Subangdaku", None, 110000, 4.60, 21),
    (21, "Davao Export Co.", "exporter", "Fresh banana and pineapple exporter to Japan, Korea and the Middle East.", 3, "Davao City", "Sasa", None, 600000, 4.50, 7),
    (22, "Lagumbay Family Farm", "smallholder", "1.5 ha cabbage and carrot plots on the slopes of Mt. Kitanglad.", 1, "Lantapan", "Songco", 1.50, 9000, 4.60, 8),
    (23, "Bayawa Upland Garden", "smallholder", "Talaandig smallholder growing cabbage and ginger.", 1, "Lantapan", "Kibangay", 1.20, 7000, 4.70, 5),
    (24, "Pabelic Vegetable Plot", "smallholder", "Carrots and potato under coop-supplied seed program.", 1, "Lantapan", "Basak", 2.00, 10000, 4.40, 4),
    (25, "Ompad Highland Farm", "smallholder", "New coop member; cabbage and sweet potato.", 1, "Lantapan", "Victory", 0.80, 4000, 0.00, 0),
    (26, "Sabanal Corn Farm", "smallholder", "ARB corn farmer, 3 ha, mechanized shelling via the coop.", 1, "Maramag", "Dologon", 3.00, 18000, 4.50, 11),
    (27, "Lagrosas Agri Farm", "smallholder", "ARB yellow corn grower; coop dryer user.", 1, "Maramag", "Panalsalan", 2.50, 15000, 4.55, 9),
    (28, "AgriMatch Quality Desk — Bukidnon", "inspection", "BPI-trained inspector for highland vegetables and grains.", 1, "Malaybalay City", "Casisang", None, None, 5.00, 0),
    (29, "AgriMatch Quality Desk — Davao", "inspection", "Fruit export inspection (PHAMS / Japan spec).", 3, "Davao City", "Bajada", None, None, 5.00, 0),
    (30, "Abellanosa Trucking (Isuzu Elf 3T)", "logistics", "Elf 4-wheeler for Bukidnon–CDO vegetable runs.", 2, "Cagayan de Oro City", "Bulua", None, None, 4.80, 64),
    (31, "Dumdum Hauling (6-wheeler 8T)", "logistics", "Forward 6-wheeler for grain and banana hauling.", 1, "Valencia City", "Poblacion", None, None, 4.70, 92),
]

# ---------------------------------------------------------------------------
# Cooperatives (CDA-registered) and memberships
# ---------------------------------------------------------------------------
# id, name, cda_reg_no, province, municipality, barangay, manager_id, member_count, founded, primary_crops, coop_fee_pct
COOPERATIVES = [
    (1, "Lantapan Highland Vegetable Growers MPC", "9520-10000214", 1, "Lantapan", "Kibangay", 4, 86, 2009,
     "Cabbage, carrots, white potato, ginger, kamote", 3.0),
    (2, "Maramag Agrarian Reform Beneficiaries MPC", "9520-10004417", 1, "Maramag", "Base Camp", 11, 140, 2004,
     "Yellow corn, white corn, palay", 2.5),
    (3, "Bongabon Onion Growers Cooperative", "9520-03008812", 10, "Bongabon", "Labi", None, 212, 1998,
     "Red Pinoy onion, Yellow Granex onion", 2.0),
]
COOP_MEMBERS = {2: 1, 4: 1, 22: 1, 23: 1, 24: 1, 25: 1, 11: 2, 26: 2, 27: 2, 7: 3}

# ---------------------------------------------------------------------------
# Trading hubs (bagsakan / terminal markets the price index is computed for)
# ---------------------------------------------------------------------------
# id, code, name, short, province, municipality, barangay, lat, lng, type, markup
TRADING_HUBS = [
    (1, "CDO-AGR", "Agora Market Vegetable Terminal", "Agora CDO", 2, "Cagayan de Oro City", "Lapasan", 8.4874, 124.6534, "terminal_market", 1.12),
    (2, "BUK-VAL", "Valencia City Bagsakan Center", "Valencia Bagsakan", 1, "Valencia City", "Poblacion", 7.9061, 125.0942, "bagsakan", 1.05),
    (3, "DVO-BNK", "Bankerohan Public Market Wholesale Hall", "Bankerohan Davao", 3, "Davao City", "Bankerohan", 7.0700, 125.6080, "terminal_market", 1.12),
    (4, "ILI-PUB", "Iligan City Public Market Trading Post", "Iligan Trading Post", 7, "Iligan City", "Poblacion", 8.2280, 124.2452, "trading_post", 1.10),
    (5, "CEB-CRB", "Carbon Market Wholesale Section", "Carbon Cebu", 11, "Cebu City", "Ermita", 10.2925, 123.8987, "terminal_market", 1.15),
    (6, "BEN-LTV", "La Trinidad Vegetable Trading Post", "La Trinidad", 9, "La Trinidad", "Km. 6 Betag", 16.4602, 120.5878, "trading_post", 1.04),
    (7, "NE-BGB", "Bongabon Onion Trading Post", "Bongabon Onion Post", 10, "Bongabon", "Poblacion", 15.6320, 121.1446, "trading_post", 1.03),
    (8, "MNL-DIV", "Divisoria Wholesale Market", "Divisoria", 13, "Manila", "Tondo", 14.6042, 120.9727, "terminal_market", 1.22),
]
HUB_BY_ID = {h[0]: h for h in TRADING_HUBS}
HUB_VARIETIES = {
    1: [5, 6, 7, 8, 9, 10, 4, 20, 13, 16, 15, 1, 12],
    2: [8, 9, 10, 4, 20, 17, 18, 19, 11, 15],
    3: [11, 12, 13, 5, 6, 14, 16, 4, 7],
    4: [13, 17, 18, 6, 7, 20, 19],
    5: [5, 6, 14, 9, 1],
    6: [8, 9, 10],
    7: [1, 2, 3, 19],
    8: [1, 2, 3, 8, 9, 10, 14, 11],
}
# Recent supply shocks injected into the last week of the hub index so the
# market-signal warnings ("+12% market spike") have something real to show.
HUB_SHOCKS = {(1, 1): 0.16, (5, 1): 0.13, (16, 1): 0.11, (4, 2): 0.10, (1, 8): 0.14, (8, 6): -0.13, (11, 3): 0.05, (9, 6): -0.07}

# Municipality centroids for pickup points & route distances.
MUNI_COORDS = {
    "Lantapan": (8.0010, 125.0230), "Claveria": (8.6100, 124.8940), "Mambajao": (9.2500, 124.7160),
    "Kapatagan": (7.9020, 123.7690), "Bongabon": (15.6320, 121.1446), "Panabo City": (7.3080, 125.6840),
    "Baloi": (8.1080, 124.2290), "Tupi": (6.3330, 124.9500), "Maramag": (7.7630, 125.0050),
    "Cagayan de Oro City": (8.4542, 124.6319), "Manolo Fortich": (8.3680, 124.8640), "Davao City": (7.1907, 125.4553),
    "Iligan City": (8.2280, 124.2452), "Cebu City": (10.3157, 123.8854),
}


def haversine(a, b):
    lat1, lng1 = map(math.radians, a)
    lat2, lng2 = map(math.radians, b)
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lng2 - lng1) / 2) ** 2
    return 6371.0 * 2 * math.asin(math.sqrt(h))


def nearest_hub(coord):
    return min(TRADING_HUBS, key=lambda h: haversine(coord, (h[7], h[8])))[0]

# ---------------------------------------------------------------------------
# Regional price index — mean-reverting random walk with seasonal drift
# ---------------------------------------------------------------------------
def build_price_index():
    rows = []
    rid = 1
    latest = {}  # (variety, province) -> farmgate on TODAY
    start = TODAY - timedelta(days=PRICE_HISTORY_DAYS - 1)
    for v in VARIETIES:
        vid, _, _, _, _, _, _, _, base, vol, provs = v
        # Seasonal shape: some crops are trending up into the Christmas season.
        trend = {1: 0.28, 2: 0.22, 3: 0.08, 5: -0.10, 8: -0.12, 14: 0.18, 16: -0.06, 4: 0.10}.get(vid, 0.03)
        for pid in provs:
            target_base = base * PROV_DIFF[pid]
            price = target_base * random.uniform(0.92, 1.05)
            for d in range(PRICE_HISTORY_DAYS):
                day = start + timedelta(days=d)
                progress = d / (PRICE_HISTORY_DAYS - 1)
                target = target_base * (1 + trend * (progress - 0.5)) * (1 + 0.04 * math.sin(d / 9.0 + pid))
                shock = random.gauss(0, vol)
                if random.random() < 0.02:  # typhoon / supply shock
                    shock += random.choice([-1, 1]) * vol * 4
                price = price + (target - price) * 0.18 + price * shock
                price = max(price, base * 0.35)
                farm = round(price, 2)
                spread = farm * random.uniform(0.03, 0.08)
                wholesale = round(farm * random.uniform(1.18, 1.32), 2)
                retail = round(wholesale * random.uniform(1.22, 1.40), 2)
                volume = round(random.uniform(800, 9000) * (5 if vid in (11, 17, 18, 19) else 1), 2)
                source = "platform" if (d + pid) % 3 else ("da_bantay_presyo" if pid in (1, 2, 3, 10, 11) else "market_survey")
                created = datetime.combine(day, datetime.min.time()).replace(hour=18)
                rows.append((rid, vid, pid, day, farm, wholesale, retail,
                             round(farm - spread, 2), round(farm + spread, 2), farm,
                             volume, random.randint(4, 46), source, created, created))
                rid += 1
                latest[(vid, pid)] = farm
    return rows, latest


PRICE_ROWS, LATEST = build_price_index()

# ---------------------------------------------------------------------------
# Harvest listings
# ---------------------------------------------------------------------------
# seller, variety, province, municipality, barangay, grade, organic, stock_kg, min_order, start_offset, window_days, status, verified, title_suffix
LISTING_SPECS = [
    (2, 8, 1, "Lantapan", "Songco", "A", False, 12000, 500, -6, 21, "active", True, "Tight heads, 1.2–1.8 kg avg"),
    (2, 9, 1, "Lantapan", "Songco", "A", False, 8500, 300, -3, 25, "active", True, "Washed, sorted Class A"),
    (2, 10, 1, "Lantapan", "Songco", "B", False, 15000, 1000, 12, 30, "upcoming", True, "Granola main crop, field-cured"),
    (3, 5, 2, "Claveria", "Patrocenio", "A", False, 6000, 200, -2, 14, "active", True, "Breaker-to-pink stage, crated"),
    (3, 6, 2, "Claveria", "Patrocenio", "A", False, 3500, 150, -1, 12, "active", True, "Long purple, glossy skin"),
    (3, 5, 2, "Claveria", "Patrocenio", "B", False, 9000, 300, 18, 21, "upcoming", True, "Second planting, Diamante Max"),
    (4, 4, 1, "Lantapan", "Kibangay", "A", True, 7000, 250, -10, 40, "active", True, "Organic native ginger, mature rhizomes"),
    (4, 20, 1, "Lantapan", "Kibangay", "A", True, 9500, 300, -4, 30, "active", True, "Violet-skin kamote, cooperative pooled"),
    (4, 8, 1, "Lantapan", "Kibangay", "B", True, 6000, 300, 25, 20, "upcoming", True, "Organic cabbage, NOCAP-in-process"),
    (5, 15, 12, "Mambajao", "Yumbing", "A", False, 20000, 1000, -5, 35, "active", True, "MD-2 sweet, Brix 15+"),
    (5, 16, 12, "Mambajao", "Yumbing", "A", False, 4000, 100, -8, 20, "active", True, "Fresh-picked, green-ripe"),
    (6, 17, 7, "Kapatagan", "Poblacion", "A", False, 60000, 5000, -12, 45, "active", False, "Shelled, 14% moisture"),
    (6, 19, 7, "Kapatagan", "Poblacion", "A", False, 45000, 5000, 20, 30, "upcoming", False, "Wet-season palay, mechanized harvest"),
    (7, 1, 10, "Bongabon", "Labi", "A", False, 25000, 1000, -30, 60, "active", True, "Cold-stored Red Pinoy, 4–6 cm bulbs"),
    (7, 2, 10, "Bongabon", "Labi", "B", False, 14000, 1000, -30, 50, "active", True, "Yellow Granex, mixed sizes"),
    (8, 11, 4, "Panabo City", "Cagangohan", "A", False, 120000, 10000, -7, 30, "active", True, "Export-grade 13 kg boxes, GCTCV-219"),
    (8, 12, 4, "Panabo City", "Cagangohan", "A", False, 18000, 500, -3, 18, "active", True, "Lakatan hands, mature-green"),
    (8, 11, 4, "Panabo City", "Cagangohan", "B", False, 60000, 5000, 15, 30, "upcoming", True, "Domestic-grade Cavendish, Class B"),
    (9, 13, 7, "Baloi", "Abaga", "A", False, 7000, 300, -4, 14, "active", False, "Saba bunches, cooking-ripe"),
    (9, 7, 7, "Baloi", "Abaga", "B", False, 2500, 100, -2, 10, "reserved", False, "Ampalaya, medium length"),
    (10, 14, 6, "Tupi", "Linan", "A", False, 9000, 300, -5, 16, "active", True, "Off-season Carabao, hot-water treated"),
    (10, 14, 6, "Tupi", "Linan", "A", False, 12000, 500, 30, 20, "upcoming", True, "Induced flowering batch #2"),
    (11, 18, 1, "Maramag", "Base Camp", "A", False, 180000, 10000, -15, 45, "active", True, "Feed-grade, aflatoxin-tested"),
    (11, 19, 1, "Maramag", "Base Camp", "A", False, 95000, 5000, -9, 40, "active", True, "Dry palay, 14% MC, ARBO pooled"),
    (11, 17, 1, "Maramag", "Base Camp", "B", False, 70000, 5000, 22, 35, "upcoming", True, "White corn grits-grade"),
    (12, 5, 2, "Cagayan de Oro City", "Cogon", "B", False, 11000, 500, -1, 7, "active", True, "Consolidated from Claveria & Manolo Fortich"),
    (12, 9, 1, "Manolo Fortich", "Dalirig", "A", False, 6000, 300, 5, 20, "upcoming", True, "Consolidated highland carrots"),
    (13, 12, 3, "Davao City", "Bankerohan", "A", False, 14000, 500, -2, 12, "active", True, "Reefer-ready lakatan"),
    (13, 4, 3, "Davao City", "Calinan", "B", False, 5000, 200, -6, 25, "active", True, "Ginger, mixed grade"),
    (14, 17, 7, "Iligan City", "Tibanga", "A", False, 150000, 10000, -20, 60, "active", True, "Warehouse stock, NFA-spec white corn"),
    (14, 18, 7, "Iligan City", "Tibanga", "A", False, 200000, 20000, -14, 60, "active", True, "Yellow corn, bulk truckload"),
    (15, 6, 11, "Cebu City", "Pasil", "B", False, 4000, 200, -1, 8, "active", False, "Eggplant transhipped from Bohol"),
    (3, 7, 2, "Claveria", "Patrocenio", "A", False, 2800, 100, 9, 14, "upcoming", True, "Ampalaya, trellised, uniform"),
    (2, 8, 1, "Lantapan", "Songco", "A", False, 5000, 500, -40, 20, "sold_out", True, "September cabbage lot"),
    (5, 15, 12, "Mambajao", "Yumbing", "B", False, 8000, 500, -55, 20, "expired", True, "Q3 pineapple seconds"),
]

TITLE_PREFIX = {
    1: "Premium", 2: "Fresh", 3: "Organic", 4: "Bulk", 5: "Farm-direct",
}


def ref(prefix, n, year=2026):
    return f"{prefix}-{year}-{n:05d}"


LISTINGS = []
for i, spec in enumerate(LISTING_SPECS, start=1):
    seller, vid, pid, muni, brgy, grade, organic, stock, min_order, start_off, window, status, verified, suffix = spec
    v = VAR_BY_ID[vid]
    mkt = LATEST.get((vid, pid)) or v[8] * PROV_DIFF[pid]
    premium = {"A": 1.04, "B": 0.93, "C": 0.85}[grade] * (1.12 if organic else 1.0)
    unit_price = round(mkt * premium * random.uniform(0.96, 1.04) * 4) / 4  # round to 0.25
    harvest_start = TODAY + timedelta(days=start_off)
    harvest_end = harvest_start + timedelta(days=window)
    if status in ("active",):
        available = round(stock * random.uniform(0.55, 0.95) / 50) * 50
    elif status == "reserved":
        available = 0
    elif status == "sold_out":
        available = 0
    elif status == "expired":
        available = round(stock * 0.2 / 50) * 50
    else:
        available = stock
    name = v[2]
    prefix = "Organic " if organic else ""
    title = f"{prefix}{name} — {suffix}"
    desc = (f"{name} from Brgy. {brgy}, {muni}, {PROV_BY_ID[pid][1]}. Grade {grade}"
            f"{', certified organic practice' if organic else ''}. Harvest window "
            f"{harvest_start.strftime('%b %d')}–{harvest_end.strftime('%b %d, %Y')}. "
            f"Minimum order {min_order:,} kg. Farm-gate pickup or delivered terms available on request.")
    created = ts(max(1, -start_off + random.randint(2, 6)) if start_off < 0 else random.randint(1, 5), hour=random.randint(7, 17))
    LISTINGS.append(dict(
        id=i, reference_code=ref("HL", i), seller_id=seller, crop_variety_id=vid, province_id=pid,
        municipality=muni, barangay=brgy, title=title, description=desc, quality_grade=grade,
        is_organic=organic, stock_volume_kg=float(stock), available_volume_kg=float(available),
        minimum_order_kg=float(min_order), unit_price=float(unit_price),
        price_negotiable=random.random() < 0.8, harvest_date_start=harvest_start, harvest_date_end=harvest_end,
        status=status, is_verified=verified, image_url=None,
        views_count=random.randint(12, 640), created_at=created, updated_at=created + timedelta(hours=random.randint(1, 48)),
        deleted_at=None,
    ))
LISTING_BY_ID = {l["id"]: l for l in LISTINGS}


# ---------------------------------------------------------------------------
# Listing extensions: cooperative tags, nearest trading hub, pickup point,
# inspection fields — then smallholder child listings + coop pool parents.
# ---------------------------------------------------------------------------
def jitter(coord, spread=0.018):
    return (round(coord[0] + random.uniform(-spread, spread), 7), round(coord[1] + random.uniform(-spread, spread), 7))


for l in LISTINGS:
    coord = jitter(MUNI_COORDS[l["municipality"]])
    l.update(cooperative_id=COOP_MEMBERS.get(l["seller_id"]), parent_listing_id=None, is_pool_parent=False,
             pooled_volume_kg=0.0, trading_hub_id=nearest_hub(coord), pickup_latitude=coord[0], pickup_longitude=coord[1],
             certified_grade=None, inspection_status="not_requested")

# child id, seller, variety, municipality, barangay, grade, stock, committed, price_mult
POOL_CHILDREN = [
    (36, 22, 8, "Lantapan", "Songco", "A", 5000, 4500, 1.00),
    (37, 23, 8, "Lantapan", "Kibangay", "A", 4000, 4000, 0.99),
    (38, 25, 8, "Lantapan", "Victory", "B", 2500, 2500, 0.96),
    (39, 24, 9, "Lantapan", "Basak", "A", 6000, 5000, 1.01),
    (40, 22, 9, "Lantapan", "Songco", "A", 3000, 3000, 1.00),
    (43, 26, 18, "Maramag", "Dologon", "A", 22000, 20000, 1.00),
    (44, 27, 18, "Maramag", "Panalsalan", "A", 16000, 16000, 0.99),
]
# parent id, coop manager (seller), coop, variety, municipality, barangay, children [(listing_id, committed)], status, title suffix
POOL_PARENTS = [
    (41, 4, 1, 8, "Lantapan", "Kibangay", [(36, 4500), (37, 4000), (38, 2500), (1, 4000)], "active",
     "Coop pooled lot from 4 Lantapan smallholders"),
    (42, 4, 1, 9, "Lantapan", "Kibangay", [(39, 5000), (40, 3000), (2, 3000)], "active",
     "Coop pooled Kuroda carrots, washed & graded"),
    (45, 11, 2, 18, "Maramag", "Base Camp", [(43, 20000), (44, 16000)], "active",
     "ARB coop pooled feed-grade corn, 14% MC"),
]

for cid, seller, vid, muni, brgy, grade, stock, committed, mult in POOL_CHILDREN:
    v = VAR_BY_ID[vid]
    mkt = LATEST.get((vid, 1)) or v[8] * PROV_DIFF[1]
    price = round(mkt * {"A": 1.04, "B": 0.93}[grade] * mult * 4) / 4
    start = TODAY - timedelta(days=random.randint(2, 6))
    coord = jitter(MUNI_COORDS[muni], 0.03)
    created = ts(random.randint(6, 12), hour=random.randint(7, 16))
    LISTINGS.append(dict(
        id=cid, reference_code=ref("HL", cid), seller_id=seller, crop_variety_id=vid, province_id=1,
        municipality=muni, barangay=brgy, title=f"{v[2]} — Smallholder lot, Brgy. {brgy}",
        description=f"{v[2]} from a {COOPERATIVES[COOP_MEMBERS[seller] - 1][1]} member farm in Brgy. {brgy}, {muni}. "
                    f"{committed:,} kg committed to the cooperative pool.",
        quality_grade=grade, is_organic=False, stock_volume_kg=float(stock), available_volume_kg=float(stock - committed),
        minimum_order_kg=100.0, unit_price=float(price), price_negotiable=True,
        harvest_date_start=start, harvest_date_end=start + timedelta(days=21),
        status="pooled" if stock == committed else "active", is_verified=seller in (22, 23, 24, 26, 27),
        image_url=None, views_count=random.randint(5, 60), created_at=created, updated_at=created, deleted_at=None,
        cooperative_id=COOP_MEMBERS[seller], parent_listing_id=None, is_pool_parent=False,
        pooled_volume_kg=float(committed), trading_hub_id=nearest_hub(coord), pickup_latitude=coord[0],
        pickup_longitude=coord[1], certified_grade=None, inspection_status="not_requested",
    ))
LISTING_BY_ID = {l["id"]: l for l in LISTINGS}

# Unpooled smallholder lots a coop manager can pool from the UI (kamote + potato).
POOL_READY = [
    (46, 23, 20, "Lantapan", "Kibangay", "A", 3500, 1.00),
    (47, 25, 20, "Lantapan", "Victory", "B", 2200, 0.97),
    (48, 24, 10, "Lantapan", "Basak", "A", 4800, 1.00),
    (49, 22, 20, "Lantapan", "Songco", "A", 2800, 1.02),
]
for lid, seller, vid, muni, brgy, grade, stock, mult in POOL_READY:
    v = VAR_BY_ID[vid]
    mkt = LATEST.get((vid, 1)) or v[8] * PROV_DIFF[1]
    start = TODAY + timedelta(days=random.randint(-3, 6))
    coord = jitter(MUNI_COORDS[muni], 0.03)
    created = ts(random.randint(1, 4), hour=random.randint(7, 16))
    LISTINGS.append(dict(
        id=lid, reference_code=ref("HL", lid), seller_id=seller, crop_variety_id=vid, province_id=1,
        municipality=muni, barangay=brgy, title=f"{v[2]} — Smallholder lot, Brgy. {brgy}",
        description=f"{v[2]} from a Lantapan Highland Vegetable Growers MPC member farm in Brgy. {brgy}.",
        quality_grade=grade, is_organic=False, stock_volume_kg=float(stock), available_volume_kg=float(stock),
        minimum_order_kg=100.0, unit_price=float(round(mkt * {"A": 1.04, "B": 0.93}[grade] * mult * 4) / 4),
        price_negotiable=True, harvest_date_start=start, harvest_date_end=start + timedelta(days=24),
        status="upcoming" if start > TODAY else "active", is_verified=seller != 25, image_url=None,
        views_count=random.randint(3, 40), created_at=created, updated_at=created, deleted_at=None,
        cooperative_id=1, parent_listing_id=None, is_pool_parent=False, pooled_volume_kg=0.0,
        trading_hub_id=nearest_hub(coord), pickup_latitude=coord[0], pickup_longitude=coord[1],
        certified_grade=None, inspection_status="not_requested",
    ))
LISTING_BY_ID = {l["id"]: l for l in LISTINGS}

for pid_, seller, coop, vid, muni, brgy, children, status, suffix in POOL_PARENTS:
    v = VAR_BY_ID[vid]
    total = sum(c[1] for c in children)
    vwap = sum(LISTING_BY_ID[c][ "unit_price"] * kg for c, kg in children) / total
    for c, kg in children:
        child = LISTING_BY_ID[c]
        child["parent_listing_id"] = pid_
        if child["pooled_volume_kg"] == 0:  # an existing listing joining the pool
            child["pooled_volume_kg"] = float(kg)
            child["available_volume_kg"] = float(max(0, child["available_volume_kg"] - kg))
    worst = max((LISTING_BY_ID[c]["quality_grade"] for c, _ in children), key=lambda g: "ABC".index(g))
    start = max(LISTING_BY_ID[c]["harvest_date_start"] for c, _ in children)
    end = max(LISTING_BY_ID[c]["harvest_date_end"] for c, _ in children)
    coord = MUNI_COORDS[muni]
    created = ts(5, hour=10)
    LISTINGS.append(dict(
        id=pid_, reference_code=ref("HL", pid_), seller_id=seller, crop_variety_id=vid, province_id=1,
        municipality=muni, barangay=brgy, title=f"{v[2]} — {suffix}",
        description=f"Parent listing aggregating {len(children)} member lots ({total:,} kg) through "
                    f"{COOPERATIVES[coop - 1][1]}. Single contract, single invoice; the cooperative handles "
                    f"consolidation, grading and pro-rata payout to member farmers.",
        quality_grade=worst, is_organic=False, stock_volume_kg=float(total), available_volume_kg=float(total),
        minimum_order_kg=1000.0, unit_price=float(round(vwap * 4) / 4), price_negotiable=True,
        harvest_date_start=start, harvest_date_end=end, status=status, is_verified=True, image_url=None,
        views_count=random.randint(80, 300), created_at=created, updated_at=created, deleted_at=None,
        cooperative_id=coop, parent_listing_id=None, is_pool_parent=True, pooled_volume_kg=0.0,
        trading_hub_id=nearest_hub(coord), pickup_latitude=coord[0], pickup_longitude=coord[1],
        certified_grade=None, inspection_status="not_requested",
    ))
LISTINGS.sort(key=lambda l: l["id"])
LISTING_BY_ID = {l["id"]: l for l in LISTINGS}

# ---------------------------------------------------------------------------
# Buyer demand (what the matching engine pairs against listings)
# ---------------------------------------------------------------------------
# buyer, variety, delivery_province, qty, price_cap_mult, from_offset, to_offset, max_km, min_grade, status, notes
DEMAND_SPECS = [
    (16, 5, 2, 4000, 1.10, 0, 14, 150, "A", "open", "Weekly tomato replenishment for 9 CDO branches. Crated, breaker stage."),
    (16, 8, 2, 6000, 1.08, 2, 21, 200, "B", "open", "Cabbage for Christmas season salad bars."),
    (17, 1, 2, 3000, 1.15, 0, 30, 1500, "A", "partially_matched", "Red onion for commissary; open to Luzon supply if landed cost works."),
    (17, 4, 2, 800, 1.12, 0, 20, 250, "B", "open", "Ginger for sauces and soups."),
    (18, 14, 3, 15000, 1.05, 10, 50, 300, "A", "open", "Carabao mango for dried-mango line. Hot-water treated preferred."),
    (18, 16, 3, 5000, 1.10, 0, 30, 400, "A", "open", "Calamansi for concentrate; juice yield >= 30%."),
    (19, 13, 7, 4000, 1.10, 0, 10, 120, "B", "open", "Saba for banana-cue vendors network."),
    (19, 17, 7, 40000, 1.06, 0, 45, 200, "B", "open", "White corn grits for member retailers."),
    (20, 6, 11, 2500, 1.12, 0, 10, 500, "B", "open", "Eggplant for hotel kitchens, Mandaue & Cebu City."),
    (20, 9, 11, 3000, 1.12, 3, 25, 600, "A", "open", "Carrots, Class A, for HRI accounts."),
    (21, 11, 3, 100000, 1.03, 0, 30, 150, "A", "partially_matched", "Export Cavendish, 13 kg boxes, Japan spec."),
    (21, 15, 3, 25000, 1.06, 0, 40, 400, "A", "open", "MD-2 for Korea trial shipment."),
    (12, 10, 2, 10000, 1.08, 10, 45, 150, "B", "open", "Potato consolidation for CDO wholesale market."),
    (14, 18, 7, 120000, 1.04, 0, 60, 250, "A", "open", "Feed mill supply contract, Q4."),
]

DEMANDS = []
for i, s in enumerate(DEMAND_SPECS, start=1):
    buyer, vid, pid, qty, cap, f_off, t_off, km, grade, status, notes = s
    mkt = LATEST.get((vid, pid)) or VAR_BY_ID[vid][8] * PROV_DIFF[pid]
    created = ts(random.randint(1, 9), hour=random.randint(8, 16))
    DEMANDS.append(dict(
        id=i, reference_code=ref("BD", i), buyer_id=buyer, crop_variety_id=vid, delivery_province_id=pid,
        quantity_kg=float(qty), max_unit_price=round(mkt * cap, 2),
        needed_from=TODAY + timedelta(days=f_off), needed_by=TODAY + timedelta(days=t_off),
        max_distance_km=km, min_quality_grade=grade, notes=notes, status=status,
        created_at=created, updated_at=created,
    ))

# ---------------------------------------------------------------------------
# Offers & contracts
# ---------------------------------------------------------------------------
# listing_id, buyer_id, demand_id, vol, price_mult, delivery_terms, status, days_ago, payment_terms, message
OFFER_SPECS = [
    (4, 16, 1, 2500, 0.97, "delivered", "accepted", 2, "net_15", "Can you deliver to our Lapasan DC twice a week?"),
    (1, 16, 2, 4000, 0.95, "delivered", "pending", 1, "net_30", "Interested in your Class A cabbage for December. Firm volume 4 MT."),
    (14, 17, 3, 2000, 0.98, "delivered", "accepted", 6, "net_15", "Landed CDO via Batangas–Cagayan RoRo; please confirm sacking."),
    (7, 17, 4, 600, 0.92, "farm_gate", "countered", 3, "cod", "Would take 600 kg at this price if pickup Friday."),
    (21, 18, 5, 8000, 0.94, "farm_gate", "pending", 2, "net_30", "Hot-water treated only. Can you hold the volume for 5 days?"),
    (11, 18, 6, 2500, 0.96, "delivered", "accepted", 7, "net_15", "Need juice-yield sample before second lot."),
    (19, 19, 7, 3000, 0.95, "pickup_hub", "pending", 1, "cod", "Pickup at Iligan hub Tuesday morning."),
    (30, 19, 8, 40000, 0.98, "delivered", "accepted", 9, "net_30", "Monthly white corn for association members."),
    (32, 20, 9, 2000, 1.00, "pickup_hub", "accepted", 1, "cod", "Take all 2 MT if crated."),
    (2, 20, 10, 2500, 0.96, "delivered", "rejected", 5, "net_30", "Landed Cebu price — can you include RoRo freight?"),
    (16, 21, 11, 60000, 0.97, "farm_gate", "accepted", 8, "lc_at_sight", "Japan-spec 13 kg boxes, PHAMS-cleared."),
    (10, 21, 12, 15000, 0.95, "delivered", "pending", 2, "net_30", "Trial shipment for Busan; Brix test required on arrival."),
    (3, 12, 13, 10000, 0.93, "farm_gate", "pending", 1, "net_15", "Forward contract for main-crop Granola."),
    (31, 14, 14, 100000, 0.99, "delivered", "accepted", 11, "net_30", "Q4 feed mill contract — staggered 4 deliveries."),
    (5, 16, None, 1200, 0.94, "delivered", "withdrawn", 4, "net_15", "Withdrawn — supplier switched internally."),
    (13, 14, None, 30000, 0.95, "farm_gate", "pending", 3, "net_30", "Interested in wet-season palay at farm gate."),
    (17, 13, None, 6000, 0.96, "farm_gate", "countered", 2, "cod", "Lakatan for Davao wet market, pickup Panabo."),
    (24, 14, None, 50000, 0.97, "delivered", "accepted", 6, "net_30", "Dry palay delivered to Iligan warehouse."),
    (26, 17, None, 1500, 0.97, "delivered", "expired", 10, "cod", "Short-notice tomato order for commissary."),
    (28, 18, None, 4000, 0.95, "delivered", "pending", 1, "net_15", "Lakatan for banana chips line."),
    (41, 16, 2, 9000, 0.98, "delivered", "accepted", 3, "net_15", "Pooled Lantapan cabbage for 9 CDO branches — weekly drops."),
    (45, 14, 14, 30000, 0.99, "delivered", "pending", 1, "net_30", "Feed mill wants the pooled ARB corn, 2 deliveries."),
    (42, 20, 10, 3000, 0.97, "delivered", "pending", 2, "net_30", "Coop carrots for Cebu HRI accounts, RoRo via CDO port."),
]

OFFERS = []
oid = 1
for spec in OFFER_SPECS:
    lid, buyer, did, vol, mult, terms, status, ago, pay, msg = spec
    l = LISTING_BY_ID[lid]
    price = round(l["unit_price"] * mult * 4) / 4
    created = ts(ago, hour=random.randint(8, 17), minute=random.choice([5, 12, 27, 41, 53]))
    responded = None if status in ("pending",) else created + timedelta(hours=random.randint(2, 30))
    expires = created + timedelta(days=5)
    base = dict(
        id=oid, reference_code=ref("OF", oid), harvest_listing_id=lid, buyer_demand_id=did,
        buyer_id=buyer, seller_id=l["seller_id"], parent_offer_id=None, offered_by_id=buyer,
        volume_kg=float(vol), unit_price=float(price), total_amount=round(vol * price, 2),
        delivery_terms=terms, delivery_date=max(l["harvest_date_start"], TODAY) + timedelta(days=random.randint(2, 7)),
        payment_terms=pay, message=msg, status=status, expires_at=expires, responded_at=responded,
        created_at=created, updated_at=responded or created,
    )
    OFFERS.append(base)
    oid += 1
    if status == "countered":
        # Seller counter-offer (child) awaiting buyer response.
        cprice = round(l["unit_price"] * 0.985 * 4) / 4
        ccreated = responded
        OFFERS.append(dict(
            id=oid, reference_code=ref("OF", oid), harvest_listing_id=lid, buyer_demand_id=did,
            buyer_id=buyer, seller_id=l["seller_id"], parent_offer_id=base["id"], offered_by_id=l["seller_id"],
            volume_kg=float(vol), unit_price=float(cprice), total_amount=round(vol * cprice, 2),
            delivery_terms=terms, delivery_date=base["delivery_date"], payment_terms=pay,
            message="Counter: best I can do given current farm-gate index. Price includes sorting and crating.",
            status="pending", expires_at=ccreated + timedelta(days=3), responded_at=None,
            created_at=ccreated, updated_at=ccreated,
        ))
        oid += 1

CONTRACTS = []
cid = 1
for o in OFFERS:
    if o["status"] != "accepted":
        continue
    l = LISTING_BY_ID[o["harvest_listing_id"]]
    signed = o["responded_at"] + timedelta(hours=3)
    days_since = (NOW - signed).days
    if days_since >= 9:
        status, delivered, completed = "completed", o["volume_kg"], signed + timedelta(days=7)
    elif days_since >= 5:
        status, delivered, completed = "in_delivery", round(o["volume_kg"] * 0.5, 2), None
    else:
        status, delivered, completed = "active", 0.0, None
    dstart = o["delivery_date"]
    CONTRACTS.append(dict(
        id=cid, contract_number=f"AMC-2026-{cid:04d}", b2b_offer_id=o["id"], harvest_listing_id=l["id"],
        buyer_id=o["buyer_id"], seller_id=o["seller_id"], agreed_volume_kg=o["volume_kg"],
        agreed_unit_price=o["unit_price"], total_value=o["total_amount"], delivered_volume_kg=float(delivered),
        delivery_terms=o["delivery_terms"], payment_terms=o["payment_terms"],
        delivery_start=dstart, delivery_end=dstart + timedelta(days=14), status=status,
        signed_by_buyer_at=signed, signed_by_seller_at=signed + timedelta(minutes=40),
        completed_at=completed, notes=None, created_at=signed, updated_at=completed or signed,
    ))
    cid += 1

# ---------------------------------------------------------------------------
# Coop pool contract allocations (pro-rata payout to member farmers)
# ---------------------------------------------------------------------------
ALLOCATIONS = []
for c in CONTRACTS:
    parent = LISTING_BY_ID[c["harvest_listing_id"]]
    if not parent["is_pool_parent"]:
        continue
    coop = COOPERATIVES[parent["cooperative_id"] - 1]
    parent["available_volume_kg"] = float(parent["stock_volume_kg"] - c["agreed_volume_kg"])
    children = [l for l in LISTINGS if l["parent_listing_id"] == parent["id"]]
    pooled = sum(ch["pooled_volume_kg"] for ch in children)
    remaining = c["agreed_volume_kg"]
    for i, ch in enumerate(children):
        kg = round(c["agreed_volume_kg"] * ch["pooled_volume_kg"] / pooled, 2) if i < len(children) - 1 else round(remaining, 2)
        remaining -= kg
        gross = round(kg * c["agreed_unit_price"], 2)
        fee = coop[10]
        ALLOCATIONS.append(dict(
            id=len(ALLOCATIONS) + 1, contract_id=c["id"], harvest_listing_id=ch["id"], farmer_id=ch["seller_id"],
            allocated_volume_kg=kg, unit_price=c["agreed_unit_price"], gross_amount=gross, coop_fee_pct=fee,
            net_payout=round(gross * (1 - fee / 100), 2), status="pending", created_at=c["created_at"], updated_at=c["created_at"],
        ))
    c["cooperative_id"] = parent["cooperative_id"]
for c in CONTRACTS:
    c.setdefault("cooperative_id", None)

# ---------------------------------------------------------------------------
# Quality inspections
# ---------------------------------------------------------------------------
GRADE_LABEL = {"export": "Export Quality", "grade_a": "Grade A", "class_b": "Class B", "class_c": "Class C", "rejected": "Rejected"}
# listing, inspector, status, certified_grade, days_ago, moisture, defects, foreign, brix, remarks
INSPECTION_SPECS = [
    (16, 29, "passed", "export", 6, None, 1.2, 0.0, None, "13 kg boxes meet Japan spec: finger length ≥ 20 cm, no latex stains, crown cut clean."),
    (10, 29, "passed", "export", 4, None, 0.8, 0.0, 15.6, "Brix 15.6 average on 30-fruit sample; shell colour stage 1–2. Korea trial spec met."),
    (21, 29, "passed", "export", 3, None, 2.1, 0.0, 14.2, "Hot-water treatment logs verified; no anthracnose on 50-fruit sample."),
    (41, 28, "passed", "grade_a", 2, None, 2.8, 0.2, None, "Pooled heads 1.2–1.9 kg, tight, no black rot. 4 member lots sampled separately."),
    (2, 28, "passed", "grade_a", 5, None, 3.1, 0.1, None, "Washed carrots, 18–22 cm, minimal forking."),
    (18, 29, "passed", "class_b", 7, None, 6.5, 0.0, None, "Domestic grade: minor scarring and mixed finger length. Suitable for local wet markets."),
    (23, 28, "passed", "grade_a", 8, 13.8, 1.5, 0.4, None, "Aflatoxin rapid test < 20 ppb. Moisture 13.8%."),
    (19, 28, "failed", "rejected", 3, None, 14.0, 0.0, None, "Over-ripe bunches and bruising above tolerance (14%). Re-sort and request re-inspection."),
    (12, 28, "requested", None, 1, None, None, None, None, None),
    (45, 28, "scheduled", None, 0, None, None, None, None, None),
    (13, None, "requested", None, 0, None, None, None, None, None),
    (42, 28, "in_progress", None, 0, None, None, None, None, None),
]
INSPECTIONS = []
for i, (lid, insp, status, cg, ago, moist, defect, foreign, brix, remarks) in enumerate(INSPECTION_SPECS, start=1):
    l = LISTING_BY_ID[lid]
    requested = ts(ago + 2, hour=9)
    inspected = ts(ago, hour=random.randint(8, 15)) if status in ("passed", "failed") else None
    INSPECTIONS.append(dict(
        id=i, reference_code=ref("QI", i), harvest_listing_id=lid, inspector_id=insp, requested_by_id=l["seller_id"],
        cooperative_id=l["cooperative_id"], status=status, certified_grade=cg,
        scheduled_at=ts(max(ago - 1, -1), hour=9) if status in ("scheduled", "in_progress", "passed", "failed") else None,
        inspected_at=inspected, sample_size_kg=float(random.choice([25, 30, 40, 50])) if inspected else None,
        moisture_pct=moist, defect_pct=defect, foreign_matter_pct=foreign, brix=brix,
        pesticide_residue_passed=True if status == "passed" else (False if status == "failed" else None),
        remarks=remarks, certificate_no=f"AMQ-2026-{i:05d}" if status == "passed" else None,
        valid_until=(inspected + timedelta(days=max(7, VAR_BY_ID[l["crop_variety_id"]][6] // 2))).date() if status == "passed" else None,
        created_at=requested, updated_at=inspected or requested,
    ))
    if status == "passed":
        l["certified_grade"], l["inspection_status"], l["is_verified"] = cg, "passed", True
    elif status == "failed":
        l["certified_grade"], l["inspection_status"] = "rejected", "failed"
    else:
        l["inspection_status"] = "pending"

# ---------------------------------------------------------------------------
# Fuel price logs (weekly DOE pump-price adjustments, every Tuesday)
# ---------------------------------------------------------------------------
FUEL_BASELINE = {"diesel": 56.00, "gasoline": 60.00}
FUEL_REGIONAL = {"NCR": -0.80, "CAR": 1.60, "III": -0.20, "VII": 0.60, "X": 0.90, "XI": 0.40, "XII": 1.20}
DIESEL_PATH = [55.10, 55.40, 55.05, 54.70, 54.95, 55.60, 56.10, 56.40, 56.00, 55.70, 56.20, 56.85, 57.30, 57.10,
               56.80, 57.25, 57.70, 58.05, 57.90, 58.30, 58.55, 58.40, 58.90, 59.70, 60.65, 61.30]
GAS_PATH = [59.80, 60.05, 59.70, 59.40, 59.90, 60.40, 60.85, 61.10, 60.70, 60.50, 60.95, 61.40, 61.85, 61.60,
            61.30, 61.70, 62.05, 62.40, 62.20, 62.55, 62.90, 62.70, 63.10, 63.60, 64.15, 64.50]
last_tuesday = TODAY - timedelta(days=(TODAY.weekday() - 1) % 7)
FUEL_LOGS = []
for region, diff in FUEL_REGIONAL.items():
    for fuel, path in (("diesel", DIESEL_PATH), ("gasoline", GAS_PATH)):
        prev = None
        for w, base_price in enumerate(path):
            eff = last_tuesday - timedelta(weeks=len(path) - 1 - w)
            price = round(base_price + diff, 2)
            FUEL_LOGS.append(dict(
                id=len(FUEL_LOGS) + 1, region_code=region, fuel_type=fuel, effective_date=eff, price_per_liter=price,
                previous_price=prev, change_amount=round(price - prev, 2) if prev is not None else None,
                source="DOE Oil Monitor", recorded_by_id=1,
                created_at=datetime.combine(eff, datetime.min.time()).replace(hour=6),
                updated_at=datetime.combine(eff, datetime.min.time()).replace(hour=6),
            ))
            prev = price


def latest_fuel(region, fuel, on):
    rows = [f for f in FUEL_LOGS if f["region_code"] == region and f["fuel_type"] == fuel and f["effective_date"] <= on]
    return max(rows, key=lambda f: f["effective_date"])


# ---------------------------------------------------------------------------
# Freight pools ("Hakot") — mirrors config/agrimatch.php + LogisticsPoolingService
# ---------------------------------------------------------------------------
ROAD_FACTOR, BACKHAUL = 1.35, 1.5
VEHICLES = [  # key, capacity_kg, fuel, flag_down, per_km
    ("multicab", 1000, "gasoline", 900, 16),
    ("elf_4w", 3000, "diesel", 2200, 28),
    ("fwd_6w", 8000, "diesel", 4000, 42),
    ("wingvan_10w", 14000, "diesel", 6500, 58),
]
FUEL_BANDS = [(-999, -5, [-2, -3, -4]), (-5, 0, [0, 0, 0]), (0, 5, [1, 2, 3]), (5, 10, [3, 5, 6.5]),
              (10, 15, [5, 8, 10]), (15, 25, [8, 12, 15]), (25, 999, [12, 16, 20])]


def road_km(a, b):
    return haversine(a, b) * ROAD_FACTOR


def pick_vehicle(kg):
    for v in VEHICLES:
        if v[1] >= kg:
            return v, 1
    return VEHICLES[-1], math.ceil(kg / VEHICLES[-1][1])


def surcharge_pct(fuel_price, fuel, km):
    delta = (fuel_price / FUEL_BASELINE[fuel] - 1) * 100
    col = 0 if km < 60 else (1 if km <= 200 else 2)
    for lo, hi, row in FUEL_BANDS:
        if lo < delta <= hi:
            return row[col], round(delta, 2)
    return 0, round(delta, 2)


def tariff(vehicle, trucks, km, region, on):
    base = trucks * (vehicle[3] + vehicle[4] * km * BACKHAUL)
    log = latest_fuel(region, vehicle[2], on)
    pct, delta = surcharge_pct(log["price_per_liter"], vehicle[2], km)
    return round(base, 2), pct, round(base * pct / 100, 2), log


def plan_route(points, hub):
    left = sorted(points, key=lambda pt: -road_km(pt[1], hub))
    route, cur = [left.pop(0)], None
    while left:
        cur = route[-1][1]
        nxt = min(left, key=lambda pt: road_km(cur, pt[1]))
        left.remove(nxt)
        route.append(nxt)
    km = sum(road_km(route[i][1], route[i + 1][1]) for i in range(len(route) - 1)) + road_km(route[-1][1], hub)
    return route, round(km, 2)


# pool id, hub, organizer, driver, departure offset, status, members [(user, listing, volume_kg)]
POOL_SPECS = [
    (1, 2, 4, 31, 2, "forming", [(2, 1, 1800), (22, 36, 900), (23, 37, 1100), (25, 38, 600)]),
    (2, 1, 4, 30, -6, "delivered", [(2, 2, 1200), (24, 39, 900), (22, 40, 700)]),
    (3, 2, 11, 31, 4, "confirmed", [(26, 43, 4200), (27, 44, 3300)]),
    (4, 1, 3, 30, 1, "forming", [(3, 4, 1500), (3, 5, 800)]),
    (5, 3, 8, None, -2, "in_transit", [(8, 17, 6000)]),
]
FREIGHT_POOLS, POOL_MEMBERS = [], []
for pid_, hub_id, org, driver, dep_off, status, members in POOL_SPECS:
    hub = HUB_BY_ID[hub_id]
    hub_pt = (hub[7], hub[8])
    region = PROV_BY_ID[hub[4]][3]
    dep = TODAY + timedelta(days=dep_off)
    pts = [((u, lid, kg), (LISTING_BY_ID[lid]["pickup_latitude"], LISTING_BY_ID[lid]["pickup_longitude"])) for u, lid, kg in members]
    route, km = plan_route(pts, hub_pt)
    total_kg = sum(m[2] for m in members)
    vehicle, trucks = pick_vehicle(total_kg)
    base, pct, sur, log = tariff(vehicle, trucks, km, region, dep)
    total = round(base + sur, 2)
    weights = {m[1]: m[2] * max(road_km((LISTING_BY_ID[m[1]]["pickup_latitude"], LISTING_BY_ID[m[1]]["pickup_longitude"]), hub_pt), 1) for m in members}
    wsum = sum(weights.values())
    first = LISTING_BY_ID[members[0][1]]
    created = ts(abs(dep_off) + 3, hour=8)
    route_json = json.dumps({"stops": [{"listing_id": r[0][1], "user_id": r[0][0], "lat": r[1][0], "lng": r[1][1], "volume_kg": r[0][2]} for r in route]
                            + [{"hub_id": hub_id, "lat": hub[7], "lng": hub[8]}]}, separators=(",", ":"))
    FREIGHT_POOLS.append(dict(
        id=pid_, reference_code=ref("FP", pid_), trading_hub_id=hub_id, origin_province_id=first["province_id"],
        origin_municipality=first["municipality"], cooperative_id=first["cooperative_id"], organizer_id=org, driver_id=driver,
        vehicle_class=vehicle[0], truck_count=trucks, capacity_kg=float(vehicle[1] * trucks), total_volume_kg=float(total_kg),
        route_distance_km=km, base_cost=base, fuel_price_log_id=log["id"], fuel_surcharge_pct=float(pct), fuel_surcharge_amount=sur,
        total_cost=total, departure_date=dep, status=status, route_json=route_json,
        last_recalculated_at=ts(0, hour=6) if status in ("forming", "confirmed") else created,
        created_at=created, updated_at=created,
    ))
    for u, lid, kg in members:
        l = LISTING_BY_ID[lid]
        d = round(road_km((l["pickup_latitude"], l["pickup_longitude"]), hub_pt), 2)
        share = round(total * weights[lid] / wsum, 2)
        sv, st = pick_vehicle(kg)
        sbase, spct, ssur, _ = tariff(sv, st, d, region, dep)
        solo = round(sbase + ssur, 2)
        mstatus = {"forming": "requested", "confirmed": "confirmed", "in_transit": "picked_up", "delivered": "delivered"}[status]
        POOL_MEMBERS.append(dict(
            id=len(POOL_MEMBERS) + 1, freight_pool_id=pid_, user_id=u, harvest_listing_id=lid, volume_kg=float(kg),
            pickup_barangay=l["barangay"], pickup_latitude=l["pickup_latitude"], pickup_longitude=l["pickup_longitude"],
            distance_to_hub_km=d, cost_share=share, solo_cost=solo, savings=round(solo - share, 2), status=mstatus,
            created_at=created, updated_at=created,
        ))

# ---------------------------------------------------------------------------
# Official price feeds + hub price index (what PriceIndexService computes)
# ---------------------------------------------------------------------------
HUB_INDEX_DAYS = 90
FEED_TRUST, CONTRACT_TRUST = 0.6, 1.0
FEEDS, HUB_INDEX = [], []
hub_start = TODAY - timedelta(days=HUB_INDEX_DAYS - 1)

# verified contract observations per (hub, variety, date), normalised to hub-delivered basis
contract_obs = {}
for c in CONTRACTS:
    l = LISTING_BY_ID[c["harvest_listing_id"]]
    hub = HUB_BY_ID[l["trading_hub_id"]]
    dist = road_km((l["pickup_latitude"], l["pickup_longitude"]), (hub[7], hub[8]))
    price = c["agreed_unit_price"] + (0.012 * dist if c["delivery_terms"] == "farm_gate" else 0)
    contract_obs.setdefault((hub[0], l["crop_variety_id"], c["signed_by_seller_at"].date()), []).append((price, c["agreed_volume_kg"]))


def signal_for(pct):
    if pct is None:
        return "stable"
    if pct >= 10: return "spike"
    if pct >= 4: return "rising"
    if pct <= -10: return "drop"
    if pct <= -4: return "falling"
    return "stable"


for hub in TRADING_HUBS:
    hid, hprov, markup = hub[0], hub[4], hub[10]
    for vid in HUB_VARIETIES[hid]:
        base = VAR_BY_ID[vid][8] * PROV_DIFF[hprov] * markup
        vol = VAR_BY_ID[vid][9]
        shock = HUB_SHOCKS.get((vid, hid), 0.0)
        price = base * random.uniform(0.95, 1.04)
        series = []
        for d in range(HUB_INDEX_DAYS):
            day = hub_start + timedelta(days=d)
            target = base * (1 + 0.035 * math.sin(d / 8.0 + hid + vid))
            days_left = HUB_INDEX_DAYS - 1 - d
            if shock and days_left < 7:
                target *= 1 + shock * (7 - days_left) / 7
                price += (target - price) * 0.55
            else:
                price += (target - price) * 0.2 + price * random.gauss(0, vol * 0.8)
            price = max(price, base * 0.4)
            feed_rows = []
            if day.weekday() < 5:
                for src in (("da_bantay_presyo", 1.0), ("da_amas", 0.985)) if hid in (1, 3, 8) else (("da_bantay_presyo", 1.0),):
                    avg = round(price * src[1] * random.uniform(0.985, 1.015), 2)
                    rvol = round(random.uniform(2000, 18000) * (6 if vid in (11, 17, 18, 19) else 1), 2)
                    feed_rows.append((avg, rvol))
                    FEEDS.append(dict(
                        id=len(FEEDS) + 1, trading_hub_id=hid, crop_variety_id=vid, report_date=day, source=src[0],
                        price_low=round(avg * random.uniform(0.9, 0.96), 2), price_high=round(avg * random.uniform(1.04, 1.1), 2),
                        price_avg=avg, reported_volume_kg=rvol,
                        reference_code=f"{'BP' if src[0] == 'da_bantay_presyo' else 'AMAS'}-{day.strftime('%Y%m%d')}-{hub[1]}-{vid:02d}",
                        ingested_at=datetime.combine(day, datetime.min.time()).replace(hour=17, minute=30),
                        created_at=datetime.combine(day, datetime.min.time()).replace(hour=17, minute=30),
                        updated_at=datetime.combine(day, datetime.min.time()).replace(hour=17, minute=30),
                    ))
            if not feed_rows and series:  # weekends carry the last feed forward at reduced weight
                feed_rows = [(series[-1]["feed_avg_price"], series[-1]["feed_volume_kg"] * 0.5)] if series[-1]["feed_avg_price"] else []
            cobs = [o for k in range(7) for o in contract_obs.get((hid, vid, day - timedelta(days=k)), [])]
            num = sum(p_ * v_ * FEED_TRUST for p_, v_ in feed_rows) + sum(p_ * v_ * CONTRACT_TRUST for p_, v_ in cobs)
            den = sum(v_ * FEED_TRUST for _, v_ in feed_rows) + sum(v_ * CONTRACT_TRUST for _, v_ in cobs)
            if den == 0:
                continue
            wavg = round(num / den, 2)
            fvol = sum(v_ for _, v_ in feed_rows)
            cvol = sum(v_ for _, v_ in cobs)
            allp = [p_ for p_, _ in feed_rows] + [p_ for p_, _ in cobs]
            prev1 = series[-1]["weighted_avg_price"] if series else None
            prev7 = next((r["weighted_avg_price"] for r in series if r["price_date"] == day - timedelta(days=7)), None)
            ch7 = round((wavg / prev7 - 1) * 100, 2) if prev7 else None
            computed = datetime.combine(day, datetime.min.time()).replace(hour=18)
            row = dict(
                trading_hub_id=hid, crop_variety_id=vid, price_date=day, weighted_avg_price=wavg,
                feed_avg_price=round(sum(p_ * v_ for p_, v_ in feed_rows) / fvol, 2) if fvol else None,
                contract_vwap=round(sum(p_ * v_ for p_, v_ in cobs) / cvol, 2) if cvol else None,
                min_price=round(min(allp) * 0.95, 2), max_price=round(max(allp) * 1.05, 2),
                feed_volume_kg=round(fvol, 2), contract_volume_kg=round(cvol, 2), feed_count=len(feed_rows),
                contract_count=len(cobs), change_1d_pct=round((wavg / prev1 - 1) * 100, 2) if prev1 else None,
                change_7d_pct=ch7, signal=signal_for(ch7), computed_at=computed, created_at=computed, updated_at=computed,
            )
            series.append(row)
        HUB_INDEX.extend(series)
for i, r in enumerate(HUB_INDEX, start=1):
    r["id"] = i

LATEST_SIGNAL = {}
for r in HUB_INDEX:
    if r["price_date"] == TODAY:
        LATEST_SIGNAL[(r["trading_hub_id"], r["crop_variety_id"])] = r

# ---------------------------------------------------------------------------
# Notifications ledger + SMS gateway log
# ---------------------------------------------------------------------------
NOTIFS, SMS = [], []


def notify(user, ntype, title, body, url, ago_h, read=False, priority="normal", sms=None):
    created = NOW - timedelta(hours=ago_h)
    nid = len(NOTIFS) + 1
    channels = "database,sms" if sms else "database"
    NOTIFS.append(dict(id=nid, user_id=user, type=ntype, title=title, body=body,
                       data=json.dumps({"url": url}, separators=(",", ":")), action_url=url, priority=priority,
                       channels=channels, read_at=created + timedelta(minutes=random.randint(5, 240)) if read else None,
                       created_at=created, updated_at=created))
    if sms:
        status, err = sms
        phone = "0" + "".join(ch for ch in next(u[4] for u in USERS if u[0] == user) if ch.isdigit())[2:]
        msg = f"AgriMatch: {title}. {body}"[:155]
        SMS.append(dict(id=len(SMS) + 1, notification_id=nid, user_id=user, recipient=phone, message=msg,
                        sender_name="AGRIMATCH", provider="semaphore",
                        provider_message_id=str(random.randint(180000000, 189999999)) if status != "failed" else None,
                        status=status, segments=1 if len(msg) <= 160 else 2, credits_used=1.0 if status in ("sent", "pending") else 0.0,
                        error_message=err, sent_at=created + timedelta(seconds=random.randint(2, 40)) if status == "sent" else None,
                        created_at=created, updated_at=created))


o_by_listing = {o["harvest_listing_id"]: o for o in OFFERS if o["parent_offer_id"] is None}
notify(2, "offer.received", "New offer on your cabbage", "Gaisano Fresh Market offered 4,000 kg at your Lantapan lot. Review within 5 days.", "/offers", 20, sms=("sent", None))
notify(2, "freight.pool_joined", "Hakot pool forming to Valencia", "3 nearby farmers joined pool FP-2026-00001. Your share is lower than a solo trip.", "/freight", 9)
notify(2, "fuel.surcharge_changed", "Fuel surcharge now +5%", "Diesel rose to ₱62.20/L in Region X (+11% vs baseline). Short-haul pools re-priced at +5%.", "/freight", 30, read=True)
notify(4, "coop_pool.offer_received", "Pooled carrots: Visayas Food Hub offer", "3,000 kg at the coop carrot pool. Members will be paid pro-rata.", "/offers", 40, sms=("sent", None))
notify(4, "contract.signed", "Contract AMC signed for pooled cabbage", "Gaisano Fresh signed 9,000 kg. Allocations created for 4 member farmers.", "/contracts", 68, read=True, priority="high")
notify(4, "inspection.passed", "Pooled cabbage certified Grade A", "Inspector Tabañag passed lot HL-2026-00041. Badge now shows on the marketplace.", "/marketplace", 46, read=True)
for u in (22, 23, 25):
    notify(u, "coop_pool.allocation", "You have a share in a coop contract", "Your committed cabbage was allocated to Gaisano Fresh contract. Payout after delivery.", "/contracts", 66, sms=("sent", None))
notify(24, "coop_pool.allocation", "Carrot pool update", "Coop received an offer for the pooled carrots. No action needed.", "/marketplace", 38, sms=("failed", "Number is not a valid PH mobile number on the Semaphore network."))
notify(26, "freight.pool_confirmed", "Truck confirmed for Nov 3", "6-wheeler pickup in Dologon. Your share is lower than a solo trip.", "/freight", 14, sms=("sent", None))
notify(27, "freight.pool_confirmed", "Truck confirmed for Nov 3", "6-wheeler pickup in Panalsalan. Your share is lower than a solo trip.", "/freight", 14, sms=("pending", None))
notify(11, "offer.received", "Feed mill offer on pooled corn", "Pangandaman Agri Supply offered 30 MT on the ARB corn pool.", "/offers", 22, sms=("sent", None), priority="high")
notify(16, "offer.accepted", "Offer accepted — sign the contract", "Lantapan coop accepted your pooled cabbage offer. Contract ready.", "/contracts", 70, read=True, priority="high")
notify(16, "price.spike", f"Red onion {LATEST_SIGNAL[(1, 1)]['change_7d_pct']:+.0f}% at Agora CDO", "7-day change at your nearest hub. Consider forward-contracting Nueva Ecija stock.", "/prices", 5)
notify(16, "price.rising", f"Tomato {LATEST_SIGNAL[(1, 5)]['change_7d_pct']:+.0f}% at Agora CDO", "Typhoon disruption in Claveria. Expect tighter supply this week.", "/prices", 3)
notify(16, "offer.countered", "Counter-offer on lakatan", "Taguinod Banana Plantation countered at a higher price.", "/offers", 26, read=True)
notify(17, "offer.countered", "Counter-offer on native ginger", "Manggubat coop countered your ginger offer.", "/offers", 48, sms=("sent", None))
notify(18, "inspection.passed", "Export certificate issued", "Carabao mango lot HL-2026-00021 passed export inspection.", "/marketplace", 70, read=True)
notify(3, "inspection.requested", "Inspection request received", "Your tomato lot is queued for the Bukidnon quality desk.", "/marketplace", 12, read=True)
notify(9, "inspection.failed", "Saba lot failed inspection", "Bruising above tolerance (14%). Re-sort and request re-inspection.", "/marketplace", 70, sms=("sent", None), priority="high")
notify(28, "inspection.assigned", "New inspection assigned", "Pooled carrots HL-2026-00042 at Lantapan — today 9:00 AM.", "/inspections", 6, sms=("sent", None))
notify(30, "freight.trip_assigned", "Trip assigned: Claveria → Agora", "Pickup Nov 1, 2 stops, 2,300 kg. Fuel surcharge applied.", "/freight", 10, sms=("queued", None))
notify(1, "fuel.price_recorded", "Diesel price logged for week of Oct 27", "Open freight pools re-priced automatically.", "/freight", 72, read=True)

# ---------------------------------------------------------------------------
# Tamper-evident audit trail (SHA-256 hash chain; mirrors App\Services\AuditLogger)
# ---------------------------------------------------------------------------
def canon(v):
    return None if v is None else json.dumps(v, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


AUDIT = []


def audit(event, atype, aid, user, new=None, old=None, when=None, ip="203.177.12.40"):
    prev = AUDIT[-1]["hash"] if AUDIT else "0" * 64
    created = when.strftime("%Y-%m-%d %H:%M:%S")
    newj, oldj = canon(new), canon(old)
    payload = "|".join([prev, event, atype or "", str(aid or ""), str(user or ""), oldj or "", newj or "", created])
    AUDIT.append(dict(id=len(AUDIT) + 1, user_id=user, event=event, auditable_type=atype, auditable_id=aid,
                      old_values=oldj, new_values=newj, ip_address=ip, user_agent="AgriMatch PWA/1.0",
                      request_id=hashlib.md5(payload.encode()).hexdigest()[:26], prev_hash=prev,
                      hash=hashlib.sha256(payload.encode()).hexdigest(), created_at=when))


events = []
for l in LISTINGS:
    if l["created_at"] >= NOW - timedelta(days=12):
        events.append((l["created_at"], "listing.created", "App\\Models\\HarvestListing", l["id"], l["seller_id"],
                       {"reference_code": l["reference_code"], "stock_volume_kg": l["stock_volume_kg"], "unit_price": l["unit_price"]}, None))
for o in OFFERS:
    if o["created_at"] >= NOW - timedelta(days=12):
        events.append((o["created_at"], "offer.created", "App\\Models\\B2bOffer", o["id"], o["offered_by_id"],
                       {"reference_code": o["reference_code"], "volume_kg": o["volume_kg"], "unit_price": o["unit_price"]}, None))
for c in CONTRACTS:
    events.append((c["signed_by_buyer_at"], "contract.signed", "App\\Models\\Contract", c["id"], c["buyer_id"],
                   {"party": "buyer", "contract_number": c["contract_number"], "total_value": c["total_value"]}, None))
    events.append((c["signed_by_seller_at"], "contract.signed", "App\\Models\\Contract", c["id"], c["seller_id"],
                   {"party": "seller", "contract_number": c["contract_number"], "total_value": c["total_value"]}, {"status": "draft"}))
for q_ in INSPECTIONS:
    if q_["inspected_at"]:
        events.append((q_["inspected_at"], f"inspection.{q_['status']}", "App\\Models\\QualityInspection", q_["id"], q_["inspector_id"],
                       {"certified_grade": q_["certified_grade"], "certificate_no": q_["certificate_no"]}, {"status": "in_progress"}))
for fp in FREIGHT_POOLS:
    events.append((fp["created_at"], "freight_pool.created", "App\\Models\\FreightPool", fp["id"], fp["organizer_id"],
                   {"reference_code": fp["reference_code"], "total_cost": fp["total_cost"], "fuel_surcharge_pct": fp["fuel_surcharge_pct"]}, None))
for par in (41, 42, 45):
    l = LISTING_BY_ID[par]
    events.append((l["created_at"], "coop_pool.created", "App\\Models\\HarvestListing", par, l["seller_id"],
                   {"children": [c["id"] for c in LISTINGS if c["parent_listing_id"] == par], "stock_volume_kg": l["stock_volume_kg"]}, None))
for f in FUEL_LOGS:
    if f["effective_date"] == last_tuesday and f["region_code"] in ("X", "XI"):
        events.append((f["created_at"], "fuel_price.recorded", "App\\Models\\FuelPriceLog", f["id"], 1,
                       {"region": f["region_code"], "fuel_type": f["fuel_type"], "price_per_liter": f["price_per_liter"]},
                       {"price_per_liter": f["previous_price"]}))
events.sort(key=lambda e: (e[0], e[1], e[3]))
for when, ev, at, aid, uid, new, old in events:
    audit(ev, at, aid, uid, new, old, when)

# ---------------------------------------------------------------------------
# DDL
# ---------------------------------------------------------------------------
DDL = r"""
-- =====================================================================
--  AgriMatch PH
--  B2B Agricultural Supply Matching, Quality Grading, Logistics Pooling
--  & Price Index Platform
--  MySQL 8.0+ / MariaDB 10.4+ dump — ready for phpMyAdmin import
--  Generated: 2026-10-30   Charset: utf8mb4 / utf8mb4_unicode_ci
--
--  Demo login for every seeded account:  password
--    admin@agrimatch.test            (admin)
--    romeo.dagatan@agrimatch.test    (farmer, Bukidnon)
--    kenneth.lim@agrimatch.test      (trader, Misamis Oriental)
--    procurement@gaisanofresh.test   (buyer, Cagayan de Oro)
--    ramil.manggubat@agrimatch.test  (coop_manager, Lantapan Highland Vegetable Growers MPC)
--    mylene.tabanag@agrimatch.test   (quality_inspector, Bukidnon)
--    junrey.abellanosa@agrimatch.test (driver, CDO)
--
--  NOTE: audit_logs is append-only — BEFORE UPDATE / BEFORE DELETE triggers
--  reject any change. phpMyAdmin handles the DELIMITER blocks at the end.
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

CREATE DATABASE IF NOT EXISTS `agrimatch_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `agrimatch_db`;

DROP TABLE IF EXISTS `audit_logs`, `sms_logs`, `notifications`, `freight_pool_members`, `freight_pools`, `fuel_price_logs`,
  `hub_price_index`, `official_price_feeds`, `contract_allocations`, `quality_inspections`, `trading_hubs`, `cooperatives`,
  `contracts`, `b2b_offers`, `buyer_demands`, `harvest_listings`, `regional_price_index`,
  `crop_varieties`, `crop_categories`, `user_profiles`, `personal_access_tokens`, `password_reset_tokens`,
  `sessions`, `cache`, `cache_locks`, `jobs`, `failed_jobs`, `users`, `provinces`, `migrations`;

-- ---------------------------------------------------------------------
-- Geography (used by the matching engine for proximity scoring)
-- ---------------------------------------------------------------------
CREATE TABLE `provinces` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `region` VARCHAR(100) NOT NULL,
  `region_code` VARCHAR(10) NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provinces_name_unique` (`name`),
  KEY `provinces_region_code_index` (`region_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `role` ENUM('farmer','coop_manager','quality_inspector','trader','buyer','driver','admin') NOT NULL DEFAULT 'buyer',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(100) DEFAULT NULL,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_status_index` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Agricultural cooperatives (CDA-registered) — pool smallholder volume
-- ---------------------------------------------------------------------
CREATE TABLE `cooperatives` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `cda_registration_no` VARCHAR(30) NOT NULL,
  `province_id` BIGINT UNSIGNED NOT NULL,
  `municipality` VARCHAR(120) NOT NULL,
  `barangay` VARCHAR(120) NOT NULL,
  `manager_id` BIGINT UNSIGNED DEFAULT NULL,
  `member_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `founded_year` SMALLINT UNSIGNED DEFAULT NULL,
  `primary_crops` VARCHAR(255) DEFAULT NULL,
  `coop_fee_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Deducted from member payouts on pooled contracts',
  `is_cda_accredited` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cooperatives_cda_registration_no_unique` (`cda_registration_no`),
  KEY `cooperatives_province_id_index` (`province_id`),
  KEY `cooperatives_manager_id_index` (`manager_id`),
  CONSTRAINT `cooperatives_province_id_foreign` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cooperatives_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `business_name` VARCHAR(255) NOT NULL,
  `business_type` ENUM('individual_farm','smallholder','cooperative','corporate_farm','trading','supermarket','food_service','processor','wholesaler','exporter','inspection','logistics','platform') NOT NULL,
  `cooperative_id` BIGINT UNSIGNED DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `province_id` BIGINT UNSIGNED DEFAULT NULL,
  `municipality` VARCHAR(120) DEFAULT NULL,
  `barangay` VARCHAR(120) DEFAULT NULL,
  `farm_size_hectares` DECIMAL(10,2) DEFAULT NULL,
  `monthly_capacity_kg` DECIMAL(14,2) DEFAULT NULL,
  `rating` DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `total_transactions` INT UNSIGNED NOT NULL DEFAULT 0,
  `avatar_url` VARCHAR(500) DEFAULT NULL,
  `sms_opt_in` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Basic-phone users receive offers/contract updates by SMS',
  `preferred_language` ENUM('en','fil','ceb') NOT NULL DEFAULT 'en',
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_profiles_user_id_unique` (`user_id`),
  KEY `user_profiles_province_id_index` (`province_id`),
  KEY `user_profiles_cooperative_id_index` (`cooperative_id`),
  CONSTRAINT `user_profiles_cooperative_id_foreign` FOREIGN KEY (`cooperative_id`) REFERENCES `cooperatives` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_profiles_province_id_foreign` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Crop catalogue
-- ---------------------------------------------------------------------
CREATE TABLE `crop_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `icon` VARCHAR(50) DEFAULT NULL,
  `color_hex` CHAR(7) DEFAULT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crop_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `crop_varieties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crop_category_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(160) NOT NULL,
  `local_name` VARCHAR(120) DEFAULT NULL,
  `scientific_name` VARCHAR(150) DEFAULT NULL,
  `unit` VARCHAR(10) NOT NULL DEFAULT 'kg',
  `shelf_life_days` SMALLINT UNSIGNED DEFAULT NULL,
  `typical_season` VARCHAR(150) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crop_varieties_slug_unique` (`slug`),
  KEY `crop_varieties_category_active_index` (`crop_category_id`, `is_active`),
  CONSTRAINT `crop_varieties_crop_category_id_foreign` FOREIGN KEY (`crop_category_id`) REFERENCES `crop_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Trading hubs — normalized regional trading posts / terminal markets
-- ---------------------------------------------------------------------
CREATE TABLE `trading_hubs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(12) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `short_name` VARCHAR(60) NOT NULL,
  `province_id` BIGINT UNSIGNED NOT NULL,
  `municipality` VARCHAR(120) NOT NULL,
  `barangay` VARCHAR(120) DEFAULT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `hub_type` ENUM('terminal_market','bagsakan','trading_post','port') NOT NULL DEFAULT 'trading_post',
  `operating_hours` VARCHAR(60) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trading_hubs_code_unique` (`code`),
  KEY `trading_hubs_province_id_index` (`province_id`),
  CONSTRAINT `trading_hubs_province_id_foreign` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supply side: harvest listings
-- ---------------------------------------------------------------------
CREATE TABLE `harvest_listings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_code` VARCHAR(20) NOT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `crop_variety_id` BIGINT UNSIGNED NOT NULL,
  `province_id` BIGINT UNSIGNED NOT NULL,
  `municipality` VARCHAR(120) NOT NULL,
  `barangay` VARCHAR(120) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quality_grade` ENUM('A','B','C') NOT NULL DEFAULT 'A',
  `is_organic` TINYINT(1) NOT NULL DEFAULT 0,
  `stock_volume_kg` DECIMAL(12,2) NOT NULL,
  `available_volume_kg` DECIMAL(12,2) NOT NULL,
  `minimum_order_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` DECIMAL(10,2) NOT NULL COMMENT 'PHP per kg',
  `price_negotiable` TINYINT(1) NOT NULL DEFAULT 1,
  `harvest_date_start` DATE NOT NULL,
  `harvest_date_end` DATE NOT NULL,
  `status` ENUM('draft','upcoming','active','reserved','pooled','sold_out','expired') NOT NULL DEFAULT 'active',
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `cooperative_id` BIGINT UNSIGNED DEFAULT NULL,
  `parent_listing_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Set on member lots aggregated into a coop pool',
  `is_pool_parent` TINYINT(1) NOT NULL DEFAULT 0,
  `pooled_volume_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Volume this member lot committed to its parent pool',
  `trading_hub_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Nearest hub — drives price signals & freight quotes',
  `pickup_latitude` DECIMAL(10,7) DEFAULT NULL,
  `pickup_longitude` DECIMAL(10,7) DEFAULT NULL,
  `certified_grade` ENUM('export','grade_a','class_b','class_c','rejected') DEFAULT NULL,
  `inspection_status` ENUM('not_requested','pending','passed','failed') NOT NULL DEFAULT 'not_requested',
  `image_url` VARCHAR(500) DEFAULT NULL,
  `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `harvest_listings_reference_code_unique` (`reference_code`),
  KEY `harvest_listings_seller_id_index` (`seller_id`),
  KEY `harvest_listings_status_harvest_index` (`status`, `harvest_date_start`, `harvest_date_end`),
  KEY `harvest_listings_variety_province_status_index` (`crop_variety_id`, `province_id`, `status`),
  KEY `harvest_listings_province_status_index` (`province_id`, `status`),
  KEY `harvest_listings_available_volume_index` (`available_volume_kg`),
  KEY `harvest_listings_unit_price_index` (`unit_price`),
  KEY `harvest_listings_deleted_at_index` (`deleted_at`),
  KEY `harvest_listings_parent_listing_id_index` (`parent_listing_id`),
  KEY `harvest_listings_cooperative_id_index` (`cooperative_id`),
  KEY `harvest_listings_trading_hub_id_index` (`trading_hub_id`),
  KEY `harvest_listings_certified_grade_index` (`certified_grade`),
  CONSTRAINT `harvest_listings_cooperative_id_foreign` FOREIGN KEY (`cooperative_id`) REFERENCES `cooperatives` (`id`) ON DELETE SET NULL,
  CONSTRAINT `harvest_listings_parent_listing_id_foreign` FOREIGN KEY (`parent_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `harvest_listings_trading_hub_id_foreign` FOREIGN KEY (`trading_hub_id`) REFERENCES `trading_hubs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `harvest_listings_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `harvest_listings_crop_variety_id_foreign` FOREIGN KEY (`crop_variety_id`) REFERENCES `crop_varieties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `harvest_listings_province_id_foreign` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `harvest_listings_volume_check` CHECK (`available_volume_kg` >= 0 AND `available_volume_kg` <= `stock_volume_kg`),
  CONSTRAINT `harvest_listings_dates_check` CHECK (`harvest_date_end` >= `harvest_date_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Demand side: buyer purchase requests (input to MatchingEngineService)
-- ---------------------------------------------------------------------
CREATE TABLE `buyer_demands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_code` VARCHAR(20) NOT NULL,
  `buyer_id` BIGINT UNSIGNED NOT NULL,
  `crop_variety_id` BIGINT UNSIGNED NOT NULL,
  `delivery_province_id` BIGINT UNSIGNED NOT NULL,
  `quantity_kg` DECIMAL(12,2) NOT NULL,
  `max_unit_price` DECIMAL(10,2) DEFAULT NULL,
  `needed_from` DATE NOT NULL,
  `needed_by` DATE NOT NULL,
  `max_distance_km` SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  `min_quality_grade` ENUM('A','B','C') NOT NULL DEFAULT 'C',
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('open','partially_matched','fulfilled','cancelled','expired') NOT NULL DEFAULT 'open',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buyer_demands_reference_code_unique` (`reference_code`),
  KEY `buyer_demands_buyer_status_index` (`buyer_id`, `status`),
  KEY `buyer_demands_variety_status_index` (`crop_variety_id`, `status`),
  CONSTRAINT `buyer_demands_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_demands_crop_variety_id_foreign` FOREIGN KEY (`crop_variety_id`) REFERENCES `crop_varieties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `buyer_demands_delivery_province_id_foreign` FOREIGN KEY (`delivery_province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Negotiation: offers & counter-offers (self-referencing thread)
-- ---------------------------------------------------------------------
CREATE TABLE `b2b_offers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_code` VARCHAR(20) NOT NULL,
  `harvest_listing_id` BIGINT UNSIGNED NOT NULL,
  `buyer_demand_id` BIGINT UNSIGNED DEFAULT NULL,
  `buyer_id` BIGINT UNSIGNED NOT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `parent_offer_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Set on counter-offers',
  `offered_by_id` BIGINT UNSIGNED NOT NULL COMMENT 'Party who authored this round',
  `volume_kg` DECIMAL(12,2) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL,
  `delivery_terms` ENUM('farm_gate','delivered','pickup_hub') NOT NULL DEFAULT 'farm_gate',
  `delivery_date` DATE DEFAULT NULL,
  `payment_terms` ENUM('cod','net_15','net_30','lc_at_sight') NOT NULL DEFAULT 'cod',
  `message` TEXT DEFAULT NULL,
  `status` ENUM('pending','countered','accepted','rejected','withdrawn','expired') NOT NULL DEFAULT 'pending',
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `responded_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `b2b_offers_reference_code_unique` (`reference_code`),
  KEY `b2b_offers_listing_status_index` (`harvest_listing_id`, `status`),
  KEY `b2b_offers_buyer_status_index` (`buyer_id`, `status`),
  KEY `b2b_offers_seller_status_index` (`seller_id`, `status`),
  KEY `b2b_offers_parent_offer_id_index` (`parent_offer_id`),
  KEY `b2b_offers_buyer_demand_id_index` (`buyer_demand_id`),
  CONSTRAINT `b2b_offers_harvest_listing_id_foreign` FOREIGN KEY (`harvest_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `b2b_offers_buyer_demand_id_foreign` FOREIGN KEY (`buyer_demand_id`) REFERENCES `buyer_demands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `b2b_offers_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `b2b_offers_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `b2b_offers_offered_by_id_foreign` FOREIGN KEY (`offered_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `b2b_offers_parent_offer_id_foreign` FOREIGN KEY (`parent_offer_id`) REFERENCES `b2b_offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_number` VARCHAR(20) NOT NULL,
  `b2b_offer_id` BIGINT UNSIGNED NOT NULL,
  `harvest_listing_id` BIGINT UNSIGNED NOT NULL,
  `buyer_id` BIGINT UNSIGNED NOT NULL,
  `seller_id` BIGINT UNSIGNED NOT NULL,
  `agreed_volume_kg` DECIMAL(12,2) NOT NULL,
  `agreed_unit_price` DECIMAL(10,2) NOT NULL,
  `total_value` DECIMAL(14,2) NOT NULL,
  `delivered_volume_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `delivery_terms` ENUM('farm_gate','delivered','pickup_hub') NOT NULL,
  `payment_terms` ENUM('cod','net_15','net_30','lc_at_sight') NOT NULL,
  `delivery_start` DATE NOT NULL,
  `delivery_end` DATE NOT NULL,
  `cooperative_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Set when the seller side is a coop pool',
  `status` ENUM('draft','active','in_delivery','completed','disputed','cancelled') NOT NULL DEFAULT 'draft',
  `signed_by_buyer_at` TIMESTAMP NULL DEFAULT NULL,
  `signed_by_seller_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contracts_contract_number_unique` (`contract_number`),
  UNIQUE KEY `contracts_b2b_offer_id_unique` (`b2b_offer_id`),
  KEY `contracts_buyer_status_index` (`buyer_id`, `status`),
  KEY `contracts_seller_status_index` (`seller_id`, `status`),
  KEY `contracts_harvest_listing_id_index` (`harvest_listing_id`),
  KEY `contracts_cooperative_id_index` (`cooperative_id`),
  CONSTRAINT `contracts_cooperative_id_foreign` FOREIGN KEY (`cooperative_id`) REFERENCES `cooperatives` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contracts_b2b_offer_id_foreign` FOREIGN KEY (`b2b_offer_id`) REFERENCES `b2b_offers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contracts_harvest_listing_id_foreign` FOREIGN KEY (`harvest_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contracts_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contracts_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Price index: one row per variety × province × day
-- ---------------------------------------------------------------------
CREATE TABLE `regional_price_index` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crop_variety_id` BIGINT UNSIGNED NOT NULL,
  `province_id` BIGINT UNSIGNED NOT NULL,
  `price_date` DATE NOT NULL,
  `farmgate_price` DECIMAL(10,2) NOT NULL,
  `wholesale_price` DECIMAL(10,2) NOT NULL,
  `retail_price` DECIMAL(10,2) NOT NULL,
  `min_price` DECIMAL(10,2) NOT NULL,
  `max_price` DECIMAL(10,2) NOT NULL,
  `avg_price` DECIMAL(10,2) NOT NULL,
  `volume_traded_kg` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `sample_size` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `source` ENUM('platform','da_bantay_presyo','market_survey') NOT NULL DEFAULT 'platform',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rpi_variety_province_date_unique` (`crop_variety_id`, `province_id`, `price_date`),
  KEY `rpi_price_date_index` (`price_date`),
  KEY `rpi_province_date_index` (`province_id`, `price_date`),
  CONSTRAINT `rpi_crop_variety_id_foreign` FOREIGN KEY (`crop_variety_id`) REFERENCES `crop_varieties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rpi_province_id_foreign` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Coop pool payouts: one row per member lot per pooled contract
-- ---------------------------------------------------------------------
CREATE TABLE `contract_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `harvest_listing_id` BIGINT UNSIGNED NOT NULL,
  `farmer_id` BIGINT UNSIGNED NOT NULL,
  `allocated_volume_kg` DECIMAL(12,2) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `gross_amount` DECIMAL(14,2) NOT NULL,
  `coop_fee_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `net_payout` DECIMAL(14,2) NOT NULL,
  `status` ENUM('pending','delivered','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_allocations_contract_listing_unique` (`contract_id`, `harvest_listing_id`),
  KEY `contract_allocations_farmer_status_index` (`farmer_id`, `status`),
  CONSTRAINT `contract_allocations_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_allocations_harvest_listing_id_foreign` FOREIGN KEY (`harvest_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contract_allocations_farmer_id_foreign` FOREIGN KEY (`farmer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Standardized quality grading workflow
-- ---------------------------------------------------------------------
CREATE TABLE `quality_inspections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_code` VARCHAR(20) NOT NULL,
  `harvest_listing_id` BIGINT UNSIGNED NOT NULL,
  `inspector_id` BIGINT UNSIGNED DEFAULT NULL,
  `requested_by_id` BIGINT UNSIGNED NOT NULL,
  `cooperative_id` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('requested','scheduled','in_progress','passed','failed','cancelled') NOT NULL DEFAULT 'requested',
  `certified_grade` ENUM('export','grade_a','class_b','class_c','rejected') DEFAULT NULL,
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `inspected_at` TIMESTAMP NULL DEFAULT NULL,
  `sample_size_kg` DECIMAL(8,2) DEFAULT NULL,
  `moisture_pct` DECIMAL(5,2) DEFAULT NULL,
  `defect_pct` DECIMAL(5,2) DEFAULT NULL,
  `foreign_matter_pct` DECIMAL(5,2) DEFAULT NULL,
  `brix` DECIMAL(5,2) DEFAULT NULL,
  `pesticide_residue_passed` TINYINT(1) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `certificate_no` VARCHAR(30) DEFAULT NULL,
  `valid_until` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quality_inspections_reference_code_unique` (`reference_code`),
  UNIQUE KEY `quality_inspections_certificate_no_unique` (`certificate_no`),
  KEY `quality_inspections_listing_status_index` (`harvest_listing_id`, `status`),
  KEY `quality_inspections_inspector_status_index` (`inspector_id`, `status`),
  CONSTRAINT `quality_inspections_harvest_listing_id_foreign` FOREIGN KEY (`harvest_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quality_inspections_inspector_id_foreign` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_requested_by_id_foreign` FOREIGN KEY (`requested_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quality_inspections_cooperative_id_foreign` FOREIGN KEY (`cooperative_id`) REFERENCES `cooperatives` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quality_inspections_pct_check` CHECK ((`defect_pct` IS NULL OR `defect_pct` BETWEEN 0 AND 100) AND (`moisture_pct` IS NULL OR `moisture_pct` BETWEEN 0 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Price & Fuel Index Engine
--   official_price_feeds : raw DA Bantay Presyo / AMAS reports per hub
--   hub_price_index      : daily weighted average computed by PriceIndexService
--   fuel_price_logs      : weekly DOE pump prices (drives the fuel surcharge matrix)
-- ---------------------------------------------------------------------
CREATE TABLE `official_price_feeds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trading_hub_id` BIGINT UNSIGNED NOT NULL,
  `crop_variety_id` BIGINT UNSIGNED NOT NULL,
  `report_date` DATE NOT NULL,
  `source` ENUM('da_bantay_presyo','da_amas','psa_farmgate','lgu_market_survey') NOT NULL,
  `price_low` DECIMAL(10,2) NOT NULL,
  `price_high` DECIMAL(10,2) NOT NULL,
  `price_avg` DECIMAL(10,2) NOT NULL,
  `reported_volume_kg` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `reference_code` VARCHAR(40) DEFAULT NULL,
  `ingested_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `opf_hub_variety_date_source_unique` (`trading_hub_id`, `crop_variety_id`, `report_date`, `source`),
  KEY `opf_report_date_index` (`report_date`),
  CONSTRAINT `opf_trading_hub_id_foreign` FOREIGN KEY (`trading_hub_id`) REFERENCES `trading_hubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opf_crop_variety_id_foreign` FOREIGN KEY (`crop_variety_id`) REFERENCES `crop_varieties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `hub_price_index` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trading_hub_id` BIGINT UNSIGNED NOT NULL,
  `crop_variety_id` BIGINT UNSIGNED NOT NULL,
  `price_date` DATE NOT NULL,
  `weighted_avg_price` DECIMAL(10,2) NOT NULL COMMENT 'Trust-weighted VWAP of verified contracts + official feeds',
  `feed_avg_price` DECIMAL(10,2) DEFAULT NULL,
  `contract_vwap` DECIMAL(10,2) DEFAULT NULL,
  `min_price` DECIMAL(10,2) NOT NULL,
  `max_price` DECIMAL(10,2) NOT NULL,
  `feed_volume_kg` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `contract_volume_kg` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `feed_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `contract_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `change_1d_pct` DECIMAL(7,2) DEFAULT NULL,
  `change_7d_pct` DECIMAL(7,2) DEFAULT NULL,
  `signal` ENUM('stable','rising','spike','falling','drop') NOT NULL DEFAULT 'stable',
  `computed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hpi_hub_variety_date_unique` (`trading_hub_id`, `crop_variety_id`, `price_date`),
  KEY `hpi_variety_date_index` (`crop_variety_id`, `price_date`),
  KEY `hpi_date_signal_index` (`price_date`, `signal`),
  CONSTRAINT `hpi_trading_hub_id_foreign` FOREIGN KEY (`trading_hub_id`) REFERENCES `trading_hubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hpi_crop_variety_id_foreign` FOREIGN KEY (`crop_variety_id`) REFERENCES `crop_varieties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fuel_price_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_code` VARCHAR(10) NOT NULL,
  `fuel_type` ENUM('diesel','gasoline') NOT NULL,
  `effective_date` DATE NOT NULL COMMENT 'Weekly DOE adjustment (Tuesdays)',
  `price_per_liter` DECIMAL(8,2) NOT NULL,
  `previous_price` DECIMAL(8,2) DEFAULT NULL,
  `change_amount` DECIMAL(8,2) DEFAULT NULL,
  `source` VARCHAR(60) NOT NULL DEFAULT 'DOE Oil Monitor',
  `recorded_by_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fuel_price_logs_region_type_date_unique` (`region_code`, `fuel_type`, `effective_date`),
  KEY `fuel_price_logs_effective_date_index` (`effective_date`),
  CONSTRAINT `fuel_price_logs_recorded_by_id_foreign` FOREIGN KEY (`recorded_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fuel_price_logs_price_check` CHECK (`price_per_liter` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Shared freight ("Hakot") pools
-- ---------------------------------------------------------------------
CREATE TABLE `freight_pools` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_code` VARCHAR(20) NOT NULL,
  `trading_hub_id` BIGINT UNSIGNED NOT NULL,
  `origin_province_id` BIGINT UNSIGNED NOT NULL,
  `origin_municipality` VARCHAR(120) NOT NULL,
  `cooperative_id` BIGINT UNSIGNED DEFAULT NULL,
  `organizer_id` BIGINT UNSIGNED NOT NULL,
  `driver_id` BIGINT UNSIGNED DEFAULT NULL,
  `vehicle_class` ENUM('multicab','elf_4w','fwd_6w','wingvan_10w') NOT NULL,
  `truck_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `capacity_kg` DECIMAL(12,2) NOT NULL,
  `total_volume_kg` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `route_distance_km` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `base_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `fuel_price_log_id` BIGINT UNSIGNED DEFAULT NULL,
  `fuel_surcharge_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `fuel_surcharge_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `departure_date` DATE NOT NULL,
  `status` ENUM('forming','confirmed','in_transit','delivered','cancelled') NOT NULL DEFAULT 'forming',
  `route_json` JSON DEFAULT NULL,
  `last_recalculated_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `freight_pools_reference_code_unique` (`reference_code`),
  KEY `freight_pools_hub_origin_status_index` (`trading_hub_id`, `origin_province_id`, `origin_municipality`, `status`),
  KEY `freight_pools_departure_status_index` (`departure_date`, `status`),
  KEY `freight_pools_driver_id_index` (`driver_id`),
  CONSTRAINT `freight_pools_trading_hub_id_foreign` FOREIGN KEY (`trading_hub_id`) REFERENCES `trading_hubs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `freight_pools_origin_province_id_foreign` FOREIGN KEY (`origin_province_id`) REFERENCES `provinces` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `freight_pools_cooperative_id_foreign` FOREIGN KEY (`cooperative_id`) REFERENCES `cooperatives` (`id`) ON DELETE SET NULL,
  CONSTRAINT `freight_pools_organizer_id_foreign` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `freight_pools_driver_id_foreign` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `freight_pools_fuel_price_log_id_foreign` FOREIGN KEY (`fuel_price_log_id`) REFERENCES `fuel_price_logs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `freight_pool_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `freight_pool_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `harvest_listing_id` BIGINT UNSIGNED DEFAULT NULL,
  `volume_kg` DECIMAL(12,2) NOT NULL,
  `pickup_barangay` VARCHAR(120) NOT NULL,
  `pickup_latitude` DECIMAL(10,7) NOT NULL,
  `pickup_longitude` DECIMAL(10,7) NOT NULL,
  `distance_to_hub_km` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `cost_share` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `solo_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'What this farmer would pay hiring the smallest fitting truck alone',
  `savings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('requested','confirmed','picked_up','delivered','withdrawn') NOT NULL DEFAULT 'requested',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fpm_pool_user_listing_unique` (`freight_pool_id`, `user_id`, `harvest_listing_id`),
  KEY `fpm_user_status_index` (`user_id`, `status`),
  CONSTRAINT `fpm_freight_pool_id_foreign` FOREIGN KEY (`freight_pool_id`) REFERENCES `freight_pools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fpm_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fpm_harvest_listing_id_foreign` FOREIGN KEY (`harvest_listing_id`) REFERENCES `harvest_listings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fpm_volume_check` CHECK (`volume_kg` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Multi-channel notifications: web bell ledger + SMS gateway log
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `body` VARCHAR(500) NOT NULL,
  `data` JSON DEFAULT NULL,
  `action_url` VARCHAR(255) DEFAULT NULL,
  `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `channels` SET('database','sms') NOT NULL DEFAULT 'database',
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_read_index` (`user_id`, `read_at`),
  KEY `notifications_user_created_index` (`user_id`, `created_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sms_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `recipient` VARCHAR(20) NOT NULL COMMENT 'PH mobile in 09XXXXXXXXX format',
  `message` VARCHAR(480) NOT NULL,
  `sender_name` VARCHAR(11) NOT NULL DEFAULT 'AGRIMATCH',
  `provider` VARCHAR(30) NOT NULL DEFAULT 'semaphore',
  `provider_message_id` VARCHAR(40) DEFAULT NULL,
  `status` ENUM('queued','pending','sent','failed','refunded','skipped') NOT NULL DEFAULT 'queued',
  `segments` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `credits_used` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_logs_status_index` (`status`),
  KEY `sms_logs_user_id_index` (`user_id`),
  KEY `sms_logs_provider_message_id_index` (`provider_message_id`),
  CONSTRAINT `sms_logs_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sms_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Enterprise audit trail — append-only, SHA-256 hash-chained.
-- hash = SHA2(prev_hash|event|auditable_type|auditable_id|user_id|old_values|new_values|created_at)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `event` VARCHAR(60) NOT NULL,
  `auditable_type` VARCHAR(120) DEFAULT NULL,
  `auditable_id` BIGINT UNSIGNED DEFAULT NULL,
  `old_values` LONGTEXT DEFAULT NULL,
  `new_values` LONGTEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `request_id` VARCHAR(40) DEFAULT NULL,
  `prev_hash` CHAR(64) NOT NULL,
  `hash` CHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `audit_logs_hash_unique` (`hash`),
  KEY `audit_logs_auditable_index` (`auditable_type`, `auditable_id`),
  KEY `audit_logs_user_created_index` (`user_id`, `created_at`),
  KEY `audit_logs_event_index` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Laravel framework tables (Sanctum tokens, sessions, cache, queues)
-- so the app runs on this dump without `php artisan migrate`.
-- ---------------------------------------------------------------------
CREATE TABLE `personal_access_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255) NOT NULL,
  `tokenable_id` BIGINT UNSIGNED NOT NULL,
  `name` TEXT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `abilities` TEXT DEFAULT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`, `tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` VARCHAR(255) NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `payload` LONGTEXT NOT NULL,
  `last_activity` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` VARCHAR(255) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  `expiration` INT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` VARCHAR(255) NOT NULL,
  `owner` VARCHAR(255) NOT NULL,
  `expiration` INT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED DEFAULT NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(255) NOT NULL,
  `connection` TEXT NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""

# ---------------------------------------------------------------------------
# Emit
# ---------------------------------------------------------------------------
parts = [DDL, "\n-- =====================================================================\n--  SEED DATA\n-- =====================================================================\n"]

seed_ts = ts(200)
parts.append(insert("provinces", ["id", "name", "region", "region_code", "latitude", "longitude", "created_at", "updated_at"],
                    [(p[0], p[1], p[2], p[3], f"{p[4]:.7f}", f"{p[5]:.7f}", seed_ts, seed_ts) for p in PROVINCES]))

user_rows = []
for u in USERS:
    uid, role, name, email, phone, verified, status = u
    created = ts(400 - uid * 11, hour=9)
    user_rows.append((uid, name, email, phone, role, verified, status, created + timedelta(hours=1),
                      PASSWORD_HASH, None, ts(random.randint(0, 6), hour=random.randint(6, 21)), created, created))
parts.append(insert("users", ["id", "name", "email", "phone", "role", "is_verified", "status", "email_verified_at",
                              "password", "remember_token", "last_login_at", "created_at", "updated_at"], user_rows))

parts.append(insert("cooperatives", ["id", "name", "cda_registration_no", "province_id", "municipality", "barangay", "manager_id",
                                     "member_count", "founded_year", "primary_crops", "coop_fee_pct", "is_cda_accredited",
                                     "created_at", "updated_at"],
                    [(c[0], c[1], c[2], c[3], c[4], c[5], c[6], c[7], c[8], c[9], c[10], True, ts(300), ts(300)) for c in COOPERATIVES]))

verified_by_user = {u[0]: u[5] for u in USERS}
prof_rows = []
for i, p in enumerate(PROFILES, start=1):
    uid, bname, btype, bio, pid, muni, brgy, ha, cap, rating, tx = p
    created = ts(400 - uid * 11, hour=10)
    prof_rows.append((i, uid, bname, btype, COOP_MEMBERS.get(uid), bio, pid, muni, brgy, ha, cap, rating, tx, None,
                      uid not in (12, 13, 14, 15, 16, 17, 18, 19, 20, 21), "ceb" if pid in (1, 2, 7, 11, 12) else "fil",
                      created + timedelta(days=3) if verified_by_user[uid] else None, created, created))
parts.append(insert("user_profiles", ["id", "user_id", "business_name", "business_type", "cooperative_id", "bio", "province_id", "municipality",
                                      "barangay", "farm_size_hectares", "monthly_capacity_kg", "rating", "total_transactions",
                                      "avatar_url", "sms_opt_in", "preferred_language", "verified_at", "created_at", "updated_at"], prof_rows))

parts.append(insert("trading_hubs", ["id", "code", "name", "short_name", "province_id", "municipality", "barangay", "latitude",
                                     "longitude", "hub_type", "operating_hours", "is_active", "created_at", "updated_at"],
                    [(h[0], h[1], h[2], h[3], h[4], h[5], h[6], f"{h[7]:.7f}", f"{h[8]:.7f}", h[9],
                      "02:00–10:00" if h[9] != "terminal_market" else "24 hours", True, seed_ts, seed_ts) for h in TRADING_HUBS]))

parts.append(insert("crop_categories", ["id", "name", "slug", "description", "icon", "color_hex", "sort_order", "created_at", "updated_at"],
                    [(c[0], c[1], c[2], c[3], c[4], c[5], c[0], seed_ts, seed_ts) for c in CATEGORIES]))

parts.append(insert("crop_varieties", ["id", "crop_category_id", "name", "slug", "local_name", "scientific_name", "unit",
                                       "shelf_life_days", "typical_season", "is_active", "created_at", "updated_at"],
                    [(v[0], v[1], v[2], v[3], v[4], v[5], "kg", v[6], v[7], True, seed_ts, seed_ts) for v in VARIETIES]))

L_COLS = ["id", "reference_code", "seller_id", "crop_variety_id", "province_id", "municipality", "barangay", "title",
          "description", "quality_grade", "is_organic", "stock_volume_kg", "available_volume_kg", "minimum_order_kg",
          "unit_price", "price_negotiable", "harvest_date_start", "harvest_date_end", "status", "is_verified",
          "cooperative_id", "parent_listing_id", "is_pool_parent", "pooled_volume_kg", "trading_hub_id",
          "pickup_latitude", "pickup_longitude", "certified_grade", "inspection_status",
          "image_url", "views_count", "created_at", "updated_at", "deleted_at"]
parts.append(insert("harvest_listings", L_COLS, [tuple(l[c] for c in L_COLS) for l in LISTINGS]))

D_COLS = ["id", "reference_code", "buyer_id", "crop_variety_id", "delivery_province_id", "quantity_kg", "max_unit_price",
          "needed_from", "needed_by", "max_distance_km", "min_quality_grade", "notes", "status", "created_at", "updated_at"]
parts.append(insert("buyer_demands", D_COLS, [tuple(d[c] for c in D_COLS) for d in DEMANDS]))

O_COLS = ["id", "reference_code", "harvest_listing_id", "buyer_demand_id", "buyer_id", "seller_id", "parent_offer_id",
          "offered_by_id", "volume_kg", "unit_price", "total_amount", "delivery_terms", "delivery_date", "payment_terms",
          "message", "status", "expires_at", "responded_at", "created_at", "updated_at"]
parts.append(insert("b2b_offers", O_COLS, [tuple(o[c] for c in O_COLS) for o in OFFERS]))

C_COLS = ["id", "contract_number", "b2b_offer_id", "harvest_listing_id", "buyer_id", "seller_id", "agreed_volume_kg",
          "agreed_unit_price", "total_value", "delivered_volume_kg", "delivery_terms", "payment_terms", "delivery_start",
          "delivery_end", "cooperative_id", "status", "signed_by_buyer_at", "signed_by_seller_at", "completed_at", "notes", "created_at", "updated_at"]
parts.append(insert("contracts", C_COLS, [tuple(c[k] for k in C_COLS) for c in CONTRACTS]))


def emit(table, rows, chunk=400):
    cols = list(rows[0].keys())
    parts.append(insert(table, cols, [tuple(r[c] for c in cols) for r in rows], chunk=chunk))


emit("contract_allocations", ALLOCATIONS)
emit("quality_inspections", INSPECTIONS)
emit("official_price_feeds", FEEDS, chunk=500)
emit("hub_price_index", HUB_INDEX, chunk=500)
emit("fuel_price_logs", FUEL_LOGS)
emit("freight_pools", FREIGHT_POOLS)
emit("freight_pool_members", POOL_MEMBERS)
emit("notifications", NOTIFS)
emit("sms_logs", SMS)
emit("audit_logs", AUDIT)

parts.append(insert("regional_price_index",
                    ["id", "crop_variety_id", "province_id", "price_date", "farmgate_price", "wholesale_price", "retail_price",
                     "min_price", "max_price", "avg_price", "volume_traded_kg", "sample_size", "source", "created_at", "updated_at"],
                    PRICE_ROWS, chunk=500))

parts.append(insert("migrations", ["id", "migration", "batch"], [
    (1, "0001_01_01_000000_create_users_table", 1),
    (2, "0001_01_01_000001_create_cache_table", 1),
    (3, "0001_01_01_000002_create_jobs_table", 1),
    (4, "2026_10_30_000001_create_personal_access_tokens_table", 1),
    (5, "2026_10_30_000002_create_agrimatch_schema", 1),
]))

# Reset AUTO_INCREMENT counters so new inserts don't collide.
for table, nxt in [("provinces", len(PROVINCES)), ("users", len(USERS)), ("user_profiles", len(PROFILES)),
                   ("crop_categories", len(CATEGORIES)), ("crop_varieties", len(VARIETIES)),
                   ("harvest_listings", len(LISTINGS)), ("buyer_demands", len(DEMANDS)), ("b2b_offers", len(OFFERS)),
                   ("contracts", len(CONTRACTS)), ("regional_price_index", len(PRICE_ROWS)), ("migrations", 5),
                   ("cooperatives", len(COOPERATIVES)), ("trading_hubs", len(TRADING_HUBS)), ("contract_allocations", len(ALLOCATIONS)),
                   ("quality_inspections", len(INSPECTIONS)), ("official_price_feeds", len(FEEDS)), ("hub_price_index", len(HUB_INDEX)),
                   ("fuel_price_logs", len(FUEL_LOGS)), ("freight_pools", len(FREIGHT_POOLS)), ("freight_pool_members", len(POOL_MEMBERS)),
                   ("notifications", len(NOTIFS)), ("sms_logs", len(SMS)), ("audit_logs", len(AUDIT))]:
    parts.append(f"ALTER TABLE `{table}` AUTO_INCREMENT = {nxt + 1};\n")

parts.append("\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n")
parts.append(r"""
-- ---------------------------------------------------------------------
-- Immutable audit trail: reject UPDATE / DELETE at the database layer
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS `audit_logs_block_update`;
DROP TRIGGER IF EXISTS `audit_logs_block_delete`;
DELIMITER $$
CREATE TRIGGER `audit_logs_block_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: UPDATE is not permitted';
END$$
CREATE TRIGGER `audit_logs_block_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: DELETE is not permitted';
END$$
DELIMITER ;
""")

OUT.write_text("".join(parts), encoding="utf-8")
print(f"hub_index={len(HUB_INDEX)} feeds={len(FEEDS)} fuel={len(FUEL_LOGS)} pools={len(FREIGHT_POOLS)} "
      f"inspections={len(INSPECTIONS)} notifications={len(NOTIFS)} sms={len(SMS)} audit={len(AUDIT)} alloc={len(ALLOCATIONS)}")
print(f"Wrote {OUT} — users={len(USERS)} listings={len(LISTINGS)} demands={len(DEMANDS)} "
      f"offers={len(OFFERS)} contracts={len(CONTRACTS)} price_rows={len(PRICE_ROWS)} "
      f"size={OUT.stat().st_size/1024:.0f} KB")
