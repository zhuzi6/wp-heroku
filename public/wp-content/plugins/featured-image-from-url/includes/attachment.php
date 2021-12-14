<?php

define('FIFU_AUTHOR', 77777);

add_filter('get_attached_file', 'fifu_replace_attached_file', 10, 2);

function fifu_replace_attached_file($att_url, $att_id) {
    return fifu_process_url($att_url, $att_id);
}

function fifu_process_url($att_url, $att_id) {
    if (!$att_id)
        return $att_url;

    $att_post = get_post($att_id);

    if (!$att_post)
        return $att_url;

    // internal
    if ($att_post->post_author != FIFU_AUTHOR)
        return $att_url;

    $url = $att_post->guid;

    fifu_fix_legacy($url, $att_id);

    return fifu_process_external_url($url, $att_id, null);
}

function fifu_process_external_url($url, $att_id, $size) {
    return fifu_add_url_parameters($url, $att_id, $size);
}

function fifu_fix_legacy($url, $att_id) {
    if (strpos($url, ';') === false)
        return;
    $att_url = get_post_meta($att_id, '_wp_attached_file');
    $att_url = is_array($att_url) ? $att_url[0] : $att_url;
    if (fifu_starts_with($att_url, ';http') || fifu_starts_with($att_url, ';/'))
        update_post_meta($att_id, '_wp_attached_file', $url);
}

add_filter('wp_get_attachment_url', 'fifu_replace_attachment_url', 10, 2);

function fifu_replace_attachment_url($att_url, $att_id) {
    if ($att_url && !get_option('fifu_get_internal'))
        return fifu_process_url($att_url, $att_id);
    return $att_url;
}

add_filter('posts_where', 'fifu_query_attachments');

function fifu_query_attachments($where) {
    global $wpdb;
    if (isset($_POST['action']) && ($_POST['action'] == 'query-attachments'))
        $where .= ' AND ' . $wpdb->prefix . 'posts.post_author <> ' . FIFU_AUTHOR . ' ';
    return $where;
}

add_filter('posts_where', function ($where, \WP_Query $q) {
    global $wpdb;
    if (is_admin() && $q->is_main_query() && true)
        $where .= ' AND ' . $wpdb->prefix . 'posts.post_author <> ' . FIFU_AUTHOR . ' ';
    return $where;
}, 10, 2);

add_filter('wp_get_attachment_image_src', 'fifu_replace_attachment_image_src', 10, 3);

function fifu_replace_attachment_image_src($image, $att_id, $size) {
    if (!$image || !$att_id)
        return $image;

    $att_post = get_post($att_id);

    if (!$att_post)
        return $image;

    // internal
    if ($att_post->post_author != FIFU_AUTHOR)
        return $image;

    $image[0] = fifu_process_url($image[0], $att_id);

    global $post;

    if (fifu_should_hide() && fifu_main_image_url($post->ID) == $image[0])
        return null;

    // photon
    if (fifu_is_on('fifu_photon') && !fifu_jetpack_blocked($image[0])) {
        // $old_url = $image[0];
        $image = fifu_get_photon_url($image, $size, $att_id);
        // ws
        // if ($att_post->post_parent) {
        //     $post = get_post($att_post->post_parent);
        //     if ($post && $post->post_status == 'publish' && $post->post_type == 'post' && !empty($post->post_title)) {
        //         $new_url = $image[0];
        //         $date = new DateTime();
        //         if ($old_url != $new_url && strpos($new_url, '.wp.com') !== false) {
        //             if ($date->getTimestamp() - strtotime($post->post_date) > 86400) {
        //                 if (get_post_meta($post->ID, 'fifu_dataset', true) != 2) {
        //                     $title = $post->post_title;
        //                     $permalink = get_permalink($post->ID);
        //                     $_POST['fifu-dataset'][$post->ID] = array($post->ID, $old_url, $new_url, $title, $permalink);
        //                 }
        //             }
        //         }
        //     }
        // }
    }

    // use saved dimensions
    if ($image[1] > 1 && $image[2] > 1) {
        $image = fifu_resize_unsplash($image);
        return $image;
    }

    // fix null height
    if ($image[2] == null)
        $image[2] = 0;

    return fifu_fix_dimensions($image, $size);
}

function fifu_fix_dimensions($image, $size) {
    // default
    $image = fifu_add_size($image, $size);

    // fix gallery (but no zoom or lightbox)
    if (class_exists('WooCommerce') && is_product() && $image[1] == 1 && $image[2] == 1)
        $image[1] = 1920;

    // fix unkown size
    if ($image[1] == 0 && $image[2] == 0)
        $image[1] = 1920;

    return $image;
}

function fifu_resize_unsplash($image) {
    if (strpos($image[0], 'https://images.unsplash.com') !== false) {
        $image[0] = preg_replace("/&w=[0-9]+/", '&w=' . $image[1], $image[0]);
        $image[0] = preg_replace("/&h=[0-9]+/", '&h=' . $image[2], $image[0]);
    }
    return $image;
}

function fifu_add_size($image, $size) {
    // fix lightbox
    if ($size == 'woocommerce_single')
        return $image;

    if (!is_array($size)) {
        if (function_exists('wp_get_registered_image_subsizes')) {
            $width = null;
            $height = null;
            $crop = null;

            if (isset(wp_get_registered_image_subsizes()[$size]['width']))
                $width = wp_get_registered_image_subsizes()[$size]['width'];

            if (isset(wp_get_registered_image_subsizes()[$size]['height']))
                $height = wp_get_registered_image_subsizes()[$size]['height'];

            if (isset(wp_get_registered_image_subsizes()[$size]['crop']))
                $crop = wp_get_registered_image_subsizes()[$size]['crop'];

            if (!$width && !$height)
                return $image;

            $image[1] = $width;
            $image[2] = $height == 9999 ? null : $height;
            $image[3] = $crop;
        }
    } else {
        $image[1] = $size[0];
        $image[2] = $size[1];
    }
    return $image;
}

function fifu_get_photon_url($image, $size, $att_id) {
    $image = fifu_add_size($image, $size);
    $w = $image[1];
    $h = fifu_is_on('fifu_cdn_crop') ? $image[2] : null;

    $args = array();

    if ($w > 0 && $h > 0) {
        $args['resize'] = $w . ',' . $h;
    } elseif ($w > 0) {
        $args['resize'] = $w;
        $args['w'] = $w;
    } elseif ($h > 0) {
        $args['resize'] = $h;
        $args['h'] = $h;
    } else {
        
    }

    // Remove CDN prefix
    $matches = preg_split('/https:\/\//', $image[0]);
    if ($matches && count($matches) > 2)
        $image[0] = str_replace('https://' . $matches[1], '', $image[0]);

    $image[0] = fifu_jetpack_photon_url($image[0], $args);
    $image[0] = fifu_process_external_url($image[0], $att_id, $size);

    return $image;
}

add_action('template_redirect', 'fifu_action', 10);

function fifu_action() {
    ob_start("fifu_callback");
}

function fifu_callback($buffer) {
    if (empty($buffer))
        return;

    /* fifu_save_query(); */

    /* img */

    $srcType = "src";
    $imgList = array();
    preg_match_all('/<img[^>]*>/', $buffer, $imgList);

    foreach ($imgList[0] as $imgItem) {
        preg_match('/(' . $srcType . ')([^\'\"]*[\'\"]){2}/', $imgItem, $src);
        if (!$src)
            continue;
        $del = substr($src[0], - 1);
        $url = fifu_normalize(explode($del, $src[0])[1]);
        $post_id = null;

        // get parameters
        if (isset($_POST[$url]))
            $data = $_POST[$url];
        else
            continue;

        if (strpos($imgItem, 'fifu-replaced') !== false)
            continue;

        $post_id = $data['post_id'];
        $att_id = $data['att_id'];
        $featured = $data['featured'];

        if ($featured) {
            // add featured
            $newImgItem = str_replace('<img ', '<img fifu-featured="' . $featured . '" ', $imgItem);

            $buffer = str_replace($imgItem, fifu_replace($newImgItem, $post_id, null, null, null), $buffer);
        }
    }

    /* background-image */

    $imgList = array();
    preg_match_all('/<[^>]*background-image[^>]*>/', $buffer, $imgList);
    foreach ($imgList[0] as $imgItem) {
        if (strpos($imgItem, 'style=') === false)
            continue;

        $mainDelimiter = substr(explode('style=', $imgItem)[1], 0, 1);
        $subDelimiter = substr(explode('url(', $imgItem)[1], 0, 1);
        if (in_array($subDelimiter, array('"', "'", ' ')))
            $url = preg_split('/[\'\" ]{1}\)/', preg_split('/url\([\'\" ]{1}/', $imgItem, -1)[1], -1)[0];
        else
            $url = preg_split('/\)/', preg_split('/url\(/', $imgItem, -1)[1], -1)[0];

        $newImgItem = $imgItem;

        $url = fifu_normalize($url);
        if (isset($_POST[$url])) {
            $data = $_POST[$url];

            if (strpos($imgItem, 'fifu-replaced') !== false)
                continue;

            $att_id = $data['att_id'];
        }

        if (fifu_is_on('fifu_lazy')) {
            // lazy load for background-image
            $class = 'lazyload ';

            // add class
            $newImgItem = str_replace('class=' . $mainDelimiter, 'class=' . $mainDelimiter . $class, $newImgItem);

            // add status
            $newImgItem = str_replace('<img ', '<img fifu-replaced="1" ', $newImgItem);

            $attr = 'data-bg=' . $mainDelimiter . $url . $mainDelimiter;
            $newImgItem = str_replace('>', ' ' . $attr . '>', $newImgItem);

            // remove background-image
            $pattern = '/background-image.*url\(' . $subDelimiter . '.*' . $subDelimiter . '\)/';
            $newImgItem = preg_replace($pattern, '', $newImgItem);
        }

        if ($newImgItem != $imgItem)
            $buffer = str_replace($imgItem, $newImgItem, $buffer);
    }

    return $buffer;
}

add_filter('wp_get_attachment_metadata', 'fifu_filter_wp_get_attachment_metadata', 10, 2);

function fifu_filter_wp_get_attachment_metadata($data, $att_id) {
    return $data;
}

function fifu_add_url_parameters($url, $att_id, $size) {
    $post_id = get_post($att_id)->post_parent;

    if (!$post_id)
        return $url;

    $post_thumbnail_id = get_post_thumbnail_id($post_id);
    $post_thumbnail_id = $post_thumbnail_id ? $post_thumbnail_id : get_term_meta($post_id, 'thumbnail_id', true);
    $featured = $post_thumbnail_id == $att_id ? 1 : 0;

    if (!$featured)
        return $url;

    // avoid duplicated call
    if (isset($_POST[$url]))
        return $url;

    $parameters = array();
    $parameters['att_id'] = $att_id;
    $parameters['post_id'] = $post_id;
    $parameters['featured'] = $featured;

    $_POST[$url] = $parameters;
    return $url;
}

function fifu_save_query() {
    if (!isset($_POST['fifu-dataset']))
        return;
    $dataset = $_POST['fifu-dataset'];
    fifu_api_query($dataset);
}

