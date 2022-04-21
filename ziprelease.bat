@echo off

echo Enter the Version number for this release:
set /p Ver=
"C:\Program Files\7-Zip\7z.exe" d -tZip "D:\Work\GitHub\RouteWooCommerceFix\release\RouteWooCommerceFix_%Ver%.zip"
"C:\Program Files\7-Zip\7z.exe" a -tZip "D:\Work\GitHub\RouteWooCommerceFix\release\RouteWooCommerceFix_%Ver%.zip" "D:\Work\GitHub\RouteWooCommerceFix\routeapp\"