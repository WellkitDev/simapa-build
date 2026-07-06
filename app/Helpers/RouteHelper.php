<?php
  if (!function_exists('active_class')) {
    function active_class($path, $active = 'active') {
        return call_user_func_array('Request::is', (array)$path) ? $active : '';
    }
}

if (!function_exists('is_active_route')) {
    function is_active_route($path) {
        return call_user_func_array('Request::is', (array)$path) ? 'true' : 'false';
    }
}

if (!function_exists('show_class')) {
    function show_class($path) {
        return call_user_func_array('Request::is', (array)$path) ? 'show' : '';
    }
}

/*
 | Nav helpers berbasis nama route (routeIs) — lebih presisi dari Request::is
 | untuk active-state sidebar (item nyala hanya untuk route & sub-route-nya).
 */
if (!function_exists('nav_active')) {
    function nav_active($names, $active = 'active') {
        return request()->routeIs(...(array) $names) ? $active : '';
    }
}

if (!function_exists('nav_show')) {
    function nav_show($names) {
        return request()->routeIs(...(array) $names) ? 'show' : '';
    }
}

if (!function_exists('nav_expanded')) {
    function nav_expanded($names) {
        return request()->routeIs(...(array) $names) ? 'true' : 'false';
    }
}

if (!function_exists('hasRole')) {
    function hasRole($role)
    {
        return auth()->check() && auth()->user()->hasRole($role);
    }
}
