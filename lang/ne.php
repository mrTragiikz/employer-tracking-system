<?php
/**
 * Nepali labels. Key = stable english slug, value = displayed Nepali text.
 * Missing keys fall back to the key itself (see helpers.php::t()).
 * Keep field-app strings short — big buttons, older users.
 */

declare(strict_types=1);

return [
    // --- generic ---
    'app_name'        => 'ट्र्याक',
    'login'           => 'लगइन',
    'logout'          => 'लगआउट',
    'save'            => 'सुरक्षित गर्नुहोस्',
    'cancel'          => 'रद्द गर्नुहोस्',
    'back'            => 'पछाडि',
    'retry'           => 'फेरि प्रयास गर्नुहोस्',
    'yes'             => 'हो',
    'no'              => 'होइन',

    // --- field login ---
    'phone'           => 'फोन नम्बर',
    'pin'             => 'पिन (४ अङ्क)',
    'enter_pin'       => 'आफ्नो ४ अङ्कको पिन हाल्नुहोस्',
    'wrong_pin'       => 'पिन मिलेन',
    'device_locked'   => 'यो खाता अर्को फोनमा बाँधिएको छ। एड्मिनलाई सम्पर्क गर्नुहोस्।',

    // --- field home / attendance ---
    'todays_route'    => 'आजको रुट',
    'start_day'       => 'दिन सुरु गर्नुहोस्',
    'end_day'         => 'दिन समाप्त गर्नुहोस्',
    'im_here'         => 'म यहाँ छु',
    'attendance'      => 'हाजिरी',
    'checked_in_at'   => 'हाजिर भएको समय',
    'checked_out_at'  => 'बाहिरिएको समय',
    'get_location'    => 'स्थान लिँदै...',
    'location_needed' => 'हाजिर गर्न स्थान (लोकेसन) खुला गर्नुपर्छ',
    'location_denied' => 'स्थान बन्द छ — हाजिर गर्न मिलेन',
    'mock_detected'   => 'नक्कली स्थान पत्ता लाग्यो — रोकियो',
    'too_far'         => 'तपाईं पसलबाट धेरै टाढा हुनुहुन्छ',
    'accuracy_low'    => 'GPS कमजोर छ — केही सेकेन्ड पर्खेर फेरि प्रयास गर्नुहोस्',

    // --- visit ---
    'select_shop'     => 'पसल छान्नुहोस्',
    'take_photo'      => 'फोटो खिच्नुहोस्',
    'photo_required'  => 'फोटो अनिवार्य छ',
    'remark'          => 'टिप्पणी',
    'visit_saved'     => 'भ्रमण सुरक्षित भयो',
    'already_visited' => 'यो पसलमा आज पहिले नै भ्रमण भइसक्यो',

    // --- end day ---
    'day_summary'     => 'दिनको सारांश',
    'total_shops'     => 'कुल पसल',
    'total_hours'     => 'कुल घण्टा',
    'shop_time'       => 'पसलमा बिताएको समय',
    'road_time'       => 'बाटोमा बिताएको समय',
    'road_distance'   => 'सडक दूरी (कि.मि.)',
    'confirm_end_day' => 'के तपाईं दिन समाप्त गर्न चाहनुहुन्छ?',
    'day_ended'       => 'दिन समाप्त भयो। धन्यवाद।',
    'no_checkout'     => 'दिन समाप्त गरिएको छैन',
];
