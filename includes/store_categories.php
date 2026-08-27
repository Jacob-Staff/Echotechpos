<?php
/**
 * PHARMANOVA ONLINE STORE CATEGORY / GROUP SOURCE OF TRUTH
 *
 * IMPORTANT:
 * Both the public store header and Online Inventory Manager load this file.
 * Add/edit categories and groups HERE. The Online Manager will automatically
 * receive the same structure without needing another hard-coded category list.
 */

$nav = [
'Medicines' => [
        'icon' => 'mdi-pill',
        'section' => 'medicines',
        'groups' => []
    ],
    'Personal Care' => [
        'icon' => 'mdi-heart-pulse',
        'section' => 'personal-care',
        'groups' => [
            'Skin Care' => ['Skin Cream','Sunscreen','Face Wash','Skin and Body Soap','Acne Care','Body Lotions','Moisturising Lotion','Moisturising Cream','Mosquito Repellent','Moisturising Gel','Body Wash'],
            'Hair Care' => ['Hair Oils','Hair Shampoo','Hair Conditioners','Hair Supplements','Hair Colour','Hair Serum','Hair Mask','Hair Solutions'],
            'Baby and Mom Care' => ['Baby Diapers and Wipes','Baby Lotion and Moisturising Cream','Baby Bath Essentials','Baby Skin Care','Baby and Infant Food','Baby Healthcare'],
            'Sexual Wellness' => ['Women Multivitamins','Ovulation Test Kit and Women Intimate Care','Sanitary Pads','Nutritional Drinks','Condoms','Lubricants','Massage Gels','Personal Body Massagers','Men Performance Booster','Sexual Health Supplements','Massage Oils','Ayurveda'],
            'Oral Care' => ['Tooth Paste','Mouth Ulcer Gel','Mouthwash','Toothache and Gum Pain','Tooth Brush','Gargle Solution'],
            'Elderly Care' => ['Orthopaedic Supports','Adult Diapers','Footwear','Mobility and Support Accessories','Urinary Support and Care']
        ]
    ],
    'Health Conditions' => [
        'icon' => 'mdi-heart-plus-outline',
        'section' => 'health-conditions',
        'groups' => [
            'Common Conditions' => ['Bone and Joint Care','Digestive Care','Eye Care','Pain Relief','Smoking Cessation','Liver Care','Stomach Care','Cold and Cough','Heart Care','Kidney Care','Piles, Fissures & Fistula','Respiratory Care','Mental Wellness','Derma Care','Pre and Probiotics'],
            'Digestive Care' => ['Acidity','Gas','Constipation','Loose Motion/Diarrhoea','Digestive Fibres','Digestive Enzymes'],
            'Eye Care' => ['Eye Lubricant Drops','Lens Solution','Safety Eye Wear','Eye Cream','Eye Vitamins and Supplements','Eye Drops','Eye Ointment and Gel'],
            'Cold, Cough & Smoking' => ['Nicotine Patch','Nicotine Gum','Nicotine Lozenges','Cough Syrups','Chest Rubs and Balms','Nasal Spray','Lozenges','Inhalant Capsules','Cold and Cough Tablets']
        ]
    ],
    'Vitamins & Supplements' => [
        'icon' => 'mdi-pill-multiple',
        'section' => 'vitamins-supplements',
        'groups' => [
            'Shop by Type' => ['Multivitamins, Multiminerals and Antioxidants','Calcium & Minerals','Vitamin A to Z','Protein Supplements','Supplement Powder','Vitamin B12 and B Complex','Mineral Supplements','Immunity Boosters','Omega and Fish Oil']
        ]
    ],
    'Diabetes Care' => [
        'icon' => 'mdi-water-outline',
        'section' => 'diabetes-care',
        'groups' => [
            'Diabetes Essentials' => ['Diabetic Diet','Sugar Substitutes','Diabetes Ayurvedic Medicines','Homeopathy','Syringes and Pens','Blood Glucose Monitors','Test Strips and Lancets']
        ]
    ],
    'Healthcare Devices' => [
        'icon' => 'mdi-medical-bag',
        'section' => 'healthcare-devices',
        'groups' => [
            'Devices & Supports' => ['Blood Glucose Monitors','Test Strips and Lancets','BP Monitors','Nebulizers and Vaporizers','Supports and Braces']
        ]
    ],
    'Homeopathic Medicine' => [
        'icon' => 'mdi-leaf',
        'section' => 'homeopathic-medicine',
        'groups' => [
            'Homeopathy' => ['Homeopathy for Skin Care','Homeopathy Digestive Care','Homeopathy for Seniors','Homeopathy Heart Care','Homeopathy Kidney Care','Homeopathy Sexual Health','Homeopathy for Diabetes Care','Homeopathy for Hair Care','Homeopathy Cold & Cough']
        ]
    ],
    'Health Guide' => [
        'icon' => 'mdi-book-open-page-variant-outline',
        'section' => 'health-guide',
        'groups' => [
            'Health Information' => ['Health Articles','Diseases & Health Conditions','Health Stories','Ayurveda','Understanding Generic Medicines','Health Library']
        ]
    ],
    'Agrovert' => [
        'icon' => 'mdi-sprout',
        'section' => 'agrovert',
        'groups' => [
            'Agrovet Products' => [
                'Veterinary Medicines',
                'Animal Health',
                'Livestock Care',
                'Poultry Care',
                'Pet Care',
                'Animal Supplements',
                'Dewormers',
                'Flea and Tick Control',
                'Vaccines',
                'Antiseptics and Disinfectants'
            ]
        ]
    ]
];

/**
 * Convert the public-header navigation tree into the classification tree
 * used by Online Inventory Manager.
 *
 * The manager receives every category and every visible group level from
 * the header. For a group that contains child links, the child labels are
 * also included so classification can match the complete header hierarchy.
 */
$product_classification = [];

foreach ($nav as $category_label => $menu) {
    $groups = [];

    foreach (($menu['groups'] ?? []) as $group_label => $children) {
        // The group itself is a valid classification target.
        $groups[] = (string)$group_label;

        // The public header displays these children under the group.
        // Include them as selectable classifications too, because they are
        // part of the header's group/navigation structure.
        if (is_array($children)) {
            foreach ($children as $child_label) {
                $child_label = trim((string)$child_label);
                if ($child_label !== '') {
                    $groups[] = $child_label;
                }
            }
        }
    }

    // Remove accidental duplicates while preserving header order.
    $product_classification[$category_label] = array_values(array_unique($groups));
}

$store_base = 'online_store.php';
$make_search_url = static function(string $term, int $bid): string {
    return 'online_store.php?bid=' . $bid . '&q=' . urlencode($term);
};
$make_section_url = static function(string $section, int $bid): string {
    return 'online_store.php?bid=' . $bid . '&section=' . urlencode($section);
};
