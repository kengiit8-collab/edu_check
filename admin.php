<?php

require_once __DIR__ . '/lib.php';

$user = require_staff_login();
$isAdmin = ($user['role'] === 'admin');
$settings = app_settings();
$page = arr_get($_GET, 'page', 'check');
ensure_attendance_room_note_column();

function deny_unless_admin($isAdmin)
{
    if (!$isAdmin) {
        http_response_code(403);
        exit('เฉพาะผู้ดูแลระบบเท่านั้น');
    }
}

function enforce_class_access($user, $classId)
{
    $allowedClassId = (int)arr_get($user, 'class_id', 0);
    if ((isset($user['role']) ? $user['role'] : '') !== 'admin' && $allowedClassId > 0 && (int)$classId !== $allowedClassId) {
        http_response_code(403);
        exit('Access denied for this class.');
    }
}

function homeroom_teachers_for_class($classId)
{
    $classId = (int)$classId;
    if ($classId <= 0) {
        return [];
    }

    $rows = all_rows(
        'SELECT t.*
         FROM class_homeroom_teachers cht
         JOIN teachers t ON t.id = cht.teacher_id
         WHERE cht.class_id = ? AND t.active = 1
         ORDER BY cht.sort_order, t.name_th',
        'i',
        [$classId]
    );
    if ($rows) {
        return $rows;
    }

    return all_rows(
        'SELECT t.*
         FROM classes c
         JOIN teachers t ON t.id = c.homeroom_teacher_id
         WHERE c.id = ? AND t.active = 1
         LIMIT 1',
        'i',
        [$classId]
    );
}

function teacher_id_is_allowed($teacherId, $teachers)
{
    foreach ($teachers as $teacher) {
        if ((int)$teacher['id'] === (int)$teacherId) {
            return true;
        }
    }
    return false;
}

function uploaded_logo_type($tmpPath)
{
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo && isset($imageInfo[2])) {
        $types = [
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
        ];
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        if (isset($types[(int)$imageInfo[2]])) {
            return [$types[(int)$imageInfo[2]], (int)$imageInfo[2], $imageInfo];
        }
    }

    return [null, null, null];
}

function logo_image_resource($tmpPath, $imageType)
{
    if ($imageType === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) {
        return @imagecreatefrompng($tmpPath);
    }
    if ($imageType === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) {
        return @imagecreatefromjpeg($tmpPath);
    }
    if ($imageType === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) {
        return @imagecreatefromgif($tmpPath);
    }
    if (defined('IMAGETYPE_WEBP') && $imageType === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($tmpPath);
    }
    return false;
}

function logo_output_path($targetPath, $extension)
{
    return preg_replace('/\.[^.]+$/', '.' . $extension, $targetPath);
}

function logo_file_under_limit($path, $maxBytes)
{
    return is_file($path) && filesize($path) <= $maxBytes;
}

function save_logo_canvas($canvas, $path, $extension, $quality)
{
    if ($extension === 'png' && function_exists('imagepng')) {
        $compression = max(0, min(9, (int)round((100 - $quality) / 11)));
        return imagepng($canvas, $path, $compression);
    }
    if ($extension === 'jpg' && function_exists('imagejpeg')) {
        return imagejpeg($canvas, $path, $quality);
    }
    if ($extension === 'webp' && function_exists('imagewebp')) {
        return imagewebp($canvas, $path, $quality);
    }
    if ($extension === 'gif' && function_exists('imagegif')) {
        return imagegif($canvas, $path);
    }
    return false;
}

function save_logo_image($tmpPath, $targetPath, $imageType, $imageInfo, $maxBytes = 1048576)
{
    $width = isset($imageInfo[0]) ? (int)$imageInfo[0] : 0;
    $height = isset($imageInfo[1]) ? (int)$imageInfo[1] : 0;
    if ($width <= 0 || $height <= 0) {
        return [false, $targetPath];
    }

    $extension = pathinfo($targetPath, PATHINFO_EXTENSION);
    if (filesize($tmpPath) <= $maxBytes) {
        return [move_uploaded_file($tmpPath, $targetPath), $targetPath];
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
        return [false, $targetPath];
    }

    $source = logo_image_resource($tmpPath, $imageType);
    if (!$source) {
        return [false, $targetPath];
    }

    $tryExtensions = [$extension];
    if ($extension === 'png' || $extension === 'gif') {
        $tryExtensions[] = 'jpg';
    }

    foreach ($tryExtensions as $tryExtension) {
        $qualitySteps = $tryExtension === 'png' || $tryExtension === 'gif' ? [90] : [85, 75, 65, 55, 45];
        $maxDimension = max($width, $height);
        while ($maxDimension >= 160) {
            $scale = min(1, $maxDimension / max($width, $height));
            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));
            $canvas = imagecreatetruecolor($newWidth, $newHeight);

            if ($tryExtension === 'png' || $tryExtension === 'webp') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
            } else {
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            foreach ($qualitySteps as $quality) {
                $savePath = logo_output_path($targetPath, $tryExtension);
                if (save_logo_canvas($canvas, $savePath, $tryExtension, $quality) && logo_file_under_limit($savePath, $maxBytes)) {
                    imagedestroy($canvas);
                    imagedestroy($source);
                    return [true, $savePath];
                }
                if (is_file($savePath) && filesize($savePath) > $maxBytes) {
                    @unlink($savePath);
                }
            }
            imagedestroy($canvas);
            $maxDimension = (int)floor($maxDimension * 0.8);
        }
    }

    imagedestroy($source);
    return [false, $targetPath];
}

function uploaded_xlsx_rows($fieldName)
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]) || (int)$_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('กรุณาเลือกไฟล์ Excel');
    }
    if ((int)$_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
    }
    if ((int)$_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('ไฟล์ Excel ต้องมีขนาดไม่เกิน 5 MB');
    }
    $extension = strtolower(pathinfo((string)$_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if ($extension !== 'xlsx') {
        throw new RuntimeException('รองรับไฟล์ Excel แบบ .xlsx เท่านั้น');
    }
    return read_xlsx_rows($_FILES[$fieldName]['tmp_name']);
}

function normalize_excel_text($value)
{
    $value = trim((string)$value);
    if (preg_match('/^\d+\.0+$/', $value)) {
        return preg_replace('/\.0+$/', '', $value);
    }
    return $value;
}

function xlsx_column_name($index)
{
    $name = '';
    $index++;
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $index = (int)(($index - $remainder) / 26);
    }
    return $name;
}

function xlsx_xml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function output_xlsx_template($fileName, $sheetName, $rows, $extraSheets = [])
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('PHP ZipArchive is required.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx-template-');
    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $allSheets = array_merge([['name' => $sheetName, 'rows' => $rows]], $extraSheets);

    $contentTypesSheets = '';
    $workbookSheets = '';
    $wbRelsSheets = '';
    foreach ($allSheets as $i => $sheet) {
        $num = $i + 1;
        $rId = 'rId' . $num;
        $contentTypesSheets .= '<Override PartName="/xl/worksheets/sheet' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="' . xlsx_xml($sheet['name']) . '" sheetId="' . $num . '" r:id="' . $rId . '"/>';
        $wbRelsSheets .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $num . '.xml"/>';
    }
    $styleRId = 'rId' . (count($allSheets) + 1);
    $themeRId = 'rId' . (count($allSheets) + 2);

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $contentTypesSheets
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Microsoft Excel</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>edu_check_demo</dc:creator><cp:lastModifiedBy>edu_check_demo</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
        . '</cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr/><bookViews><workbookView/></bookViews>'
        . '<sheets>' . $workbookSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $wbRelsSheets
        . '<Relationship Id="' . $styleRId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="' . $themeRId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
        . '</Relationships>');

    foreach ($allSheets as $i => $sheet) {
        $num = $i + 1;
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sheet['rows'] as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $sheetXml .= '<row r="' . $excelRow . '">';
            foreach ($row as $colIndex => $value) {
                $cellRef = xlsx_column_name($colIndex) . $excelRow;
                $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . xlsx_xml($value) . '</t></is></c>';
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $num . '.xml', $sheetXml);
    }
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
        . '</styleSheet>');
    $zip->addFromString('xl/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme">'
        . '<a:themeElements><a:clrScheme name="Office">'
        . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="1F497D"/></a:dk2><a:lt2><a:srgbClr val="EEECE1"/></a:lt2>'
        . '<a:accent1><a:srgbClr val="4F81BD"/></a:accent1><a:accent2><a:srgbClr val="C0504D"/></a:accent2>'
        . '<a:accent3><a:srgbClr val="9BBB59"/></a:accent3><a:accent4><a:srgbClr val="8064A2"/></a:accent4>'
        . '<a:accent5><a:srgbClr val="4BACC6"/></a:accent5><a:accent6><a:srgbClr val="F79646"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="0000FF"/></a:hlink><a:folHlink><a:srgbClr val="800080"/></a:folHlink>'
        . '</a:clrScheme><a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri"/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme><a:fmtScheme name="Office"/></a:themeElements></a:theme>');
    $zip->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

function is_teacher_import_header_row($code, $name, $phone)
{
    $text = $code . '|' . $name . '|' . $phone;
    return strpos($text, 'รหัสครู') !== false
        || strpos($text, 'ชื่อครู') !== false
        || strpos($text, 'โทรศัพท์') !== false;
}

function is_student_import_header_row($studentNo, $studentCode, $prefix, $firstName, $lastName)
{
    $text = $studentNo . '|' . $studentCode . '|' . $prefix . '|' . $firstName . '|' . $lastName;
    return strpos($text, 'เลขที่') !== false
        || strpos($text, 'รหัสนักเรียน') !== false
        || strpos($text, 'เลขประจำตัว') !== false
        || strpos($text, 'คำนำหน้า') !== false
        || strpos($text, 'ชื่อ') !== false
        || strpos($text, 'นามสกุล') !== false;
}

function download_template_file($path, $fileName)
{
    if (!is_file($path)) {
        return false;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isAdmin && arr_get($_GET, 'download_template') === 'teachers') {
    download_template_file(__DIR__ . '/teacher_import_template.xlsx', 'teacher_import_template.xlsx');
    output_xlsx_template('teacher_import_template.xlsx', 'teachers', [
        ['รหัสครู', 'ชื่อครู', 'โทรศัพท์'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isAdmin && arr_get($_GET, 'download_template') === 'students') {
    $classRefRows = [['class_id', 'ห้อง']];
    foreach (all_rows('SELECT id, class_name FROM classes ORDER BY id') as $cls) {
        $classRefRows[] = [(string)$cls['id'], $cls['class_name']];
    }
    output_xlsx_template('student_import_template.xlsx', 'students', [
        ['เลขที่', 'รหัสนักเรียน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'class_id (ห้อง)'],
        ['1', '12345', 'เด็กชาย', 'สมชาย', 'ใจดี', '1'],
    ], [['name' => 'อ้างอิงห้อง', 'rows' => $classRefRows]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = arr_get($_POST, 'action', '');

    if ($action === 'settings') {
        deny_unless_admin($isAdmin);
        set_setting('school_name', trim(arr_get($_POST, 'school_name', '')));
        set_setting('academic_year', trim(arr_get($_POST, 'academic_year', '2569')));
        set_setting('term', trim(arr_get($_POST, 'term', '1')));
        if (isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (int)$_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                flash('อัปโหลดโลโก้ไม่สำเร็จ กรุณาลองใหม่');
                redirect('admin.php?page=settings');
            }
            if ((int)$_FILES['logo_file']['size'] > 15 * 1024 * 1024) {
                flash('ไฟล์โลโก้ต้นฉบับต้องมีขนาดไม่เกิน 15 MB');
                redirect('admin.php?page=settings');
            }
            list($logoExtension, $logoImageType, $logoImageInfo) = uploaded_logo_type($_FILES['logo_file']['tmp_name']);
            if (!$logoExtension || !$logoImageType || !$logoImageInfo) {
                flash('รองรับเฉพาะไฟล์โลโก้ชนิด PNG, JPG, GIF หรือ WEBP');
                redirect('admin.php?page=settings');
            }
            $uploadDir = __DIR__ . '/img';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $fileName = 'logo-' . date('YmdHis') . '.' . $logoExtension;
            $targetPath = $uploadDir . '/' . $fileName;
            list($savedLogo, $savedLogoPath) = save_logo_image($_FILES['logo_file']['tmp_name'], $targetPath, $logoImageType, $logoImageInfo);
            if (!$savedLogo) {
                flash('ไม่สามารถบันทึกหรือย่อไฟล์โลโก้ให้ไม่เกิน 1 MB ได้');
                redirect('admin.php?page=settings');
            }
            set_setting('logo_path', 'img/' . basename($savedLogoPath));
        }
        flash('บันทึกการตั้งค่าระบบแล้ว');
        redirect('admin.php?page=settings');
    }

    if ($action === 'teacher') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $code = trim(arr_get($_POST, 'teacher_code', ''));
        $name = trim(arr_get($_POST, 'name_th', ''));
        $phone = trim(arr_get($_POST, 'phone', ''));
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE teachers SET teacher_code = ?, name_th = ?, phone = ? WHERE id = ?');
            $stmt->bind_param('sssi', $code, $name, $phone, $id);
        } else {
            $stmt = db()->prepare('INSERT INTO teachers (teacher_code, name_th, phone) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $code, $name, $phone);
        }
        $stmt->execute();
        flash('บันทึกข้อมูลครูแล้ว');
        redirect('admin.php?page=teachers');
    }

    if ($action === 'teacher_delete') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $stmt = db()->prepare('UPDATE teachers SET active = 0 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $stmt = db()->prepare('UPDATE classes SET homeroom_teacher_id = NULL WHERE homeroom_teacher_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $stmt = db()->prepare('UPDATE users SET teacher_id = NULL WHERE teacher_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();

        flash('ลบข้อมูลครูแล้ว');
        redirect('admin.php?page=teachers');
    }

    if ($action === 'teacher_import_excel') {
        deny_unless_admin($isAdmin);
        try {
            $rows = uploaded_xlsx_rows('teacher_file');
            $count = 0;
            $skipped = 0;
            foreach ($rows as $index => $row) {
                $code = normalize_excel_text(isset($row[0]) ? $row[0] : '');
                $name = trim(isset($row[1]) ? $row[1] : '');
                $phone = normalize_excel_text(isset($row[2]) ? $row[2] : '');
                if (is_teacher_import_header_row($code, $name, $phone)) {
                    continue;
                }
                if ($name === '') {
                    $skipped++;
                    continue;
                }
                $existing = null;
                if ($code !== '') {
                    $existing = one_row('SELECT id FROM teachers WHERE teacher_code = ? LIMIT 1', 's', [$code]);
                }
                if (!$existing) {
                    $existing = one_row('SELECT id FROM teachers WHERE name_th = ? LIMIT 1', 's', [$name]);
                }
                if ($existing) {
                    $id = (int)$existing['id'];
                    $stmt = db()->prepare('UPDATE teachers SET teacher_code = ?, name_th = ?, phone = ?, active = 1 WHERE id = ?');
                    $stmt->bind_param('sssi', $code, $name, $phone, $id);
                } else {
                    $stmt = db()->prepare('INSERT INTO teachers (teacher_code, name_th, phone, active) VALUES (?, ?, ?, 1)');
                    $stmt->bind_param('sss', $code, $name, $phone);
                }
                $stmt->execute();
                $count++;
            }
            flash('นำเข้าข้อมูลครู ' . $count . ' รายการ' . ($skipped ? ' ข้าม ' . $skipped . ' รายการ' : ''));
        } catch (Exception $e) {
            flash($e->getMessage());
        }
        redirect('admin.php?page=import_teachers');
    }

    if ($action === 'class') {
        deny_unless_admin($isAdmin);
        $className = normalize_class_name(arr_get($_POST, 'class_name', ''));
        $roomNo = trim(arr_get($_POST, 'room_no', ''));
        $teacherId = (int)arr_get($_POST, 'homeroom_teacher_id', 0) ?: null;
        $stmt = db()->prepare('INSERT INTO classes (class_name, room_no, homeroom_teacher_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $className, $roomNo, $teacherId);
        $stmt->execute();
        $classId = (int)db()->insert_id;
        if ($teacherId && $classId > 0) {
            $stmt = db()->prepare('INSERT IGNORE INTO class_homeroom_teachers (class_id, teacher_id, sort_order) VALUES (?, ?, 1)');
            $stmt->bind_param('ii', $classId, $teacherId);
            $stmt->execute();
        }
        flash('เพิ่มห้องเรียนแล้ว');
        redirect('admin.php?page=classes');
    }

    if ($action === 'class_update') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $className = normalize_class_name(arr_get($_POST, 'class_name', ''));
        $roomNo = trim(arr_get($_POST, 'room_no', ''));
        $teacherId = (int)arr_get($_POST, 'homeroom_teacher_id', 0) ?: null;
        $stmt = db()->prepare('UPDATE classes SET class_name = ?, room_no = ?, homeroom_teacher_id = ? WHERE id = ?');
        $stmt->bind_param('ssii', $className, $roomNo, $teacherId, $id);
        $stmt->execute();
        if ($teacherId) {
            $stmt = db()->prepare('INSERT IGNORE INTO class_homeroom_teachers (class_id, teacher_id, sort_order) VALUES (?, ?, 1)');
            $stmt->bind_param('ii', $id, $teacherId);
            $stmt->execute();
        }
        flash('แก้ไขห้องเรียนแล้ว');
        redirect('admin.php?page=classes');
    }

    if ($action === 'class_delete') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $stmt = db()->prepare('UPDATE classes SET active = 0 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash('ลบห้องเรียนแล้ว');
        redirect('admin.php?page=classes');
    }

    if ($action === 'subject') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $subjectCode = trim(arr_get($_POST, 'subject_code', ''));
        $subjectName = trim(arr_get($_POST, 'subject_name', ''));
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE subjects SET subject_code = ?, subject_name = ?, active = 1 WHERE id = ?');
            $stmt->bind_param('ssi', $subjectCode, $subjectName, $id);
        } else {
            $stmt = db()->prepare('INSERT INTO subjects (subject_code, subject_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), active = 1');
            $stmt->bind_param('ss', $subjectCode, $subjectName);
        }
        $stmt->execute();
        flash('บันทึกรายวิชาแล้ว');
        redirect('admin.php?page=subjects');
    }

    if ($action === 'subject_delete') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $stmt = db()->prepare('UPDATE subjects SET active = 0 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash('ลบรายวิชาแล้ว');
        redirect('admin.php?page=subjects');
    }

    if ($action === 'student') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $studentNo = (int)arr_get($_POST, 'student_no', 0) ?: null;
        $classId = (int)$_POST['class_id'];
        $studentCode = trim(arr_get($_POST, 'student_code', ''));
        $prefix = trim(arr_get($_POST, 'prefix_th', ''));
        $firstName = trim(arr_get($_POST, 'first_name_th', ''));
        $lastName = trim(arr_get($_POST, 'last_name_th', ''));
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE students SET student_no = ?, student_code = ?, prefix_th = ?, first_name_th = ?, last_name_th = ?, class_id = ?, active = 1 WHERE id = ?');
            $stmt->bind_param('issssii', $studentNo, $studentCode, $prefix, $firstName, $lastName, $classId, $id);
        } else {
            $stmt = db()->prepare('INSERT INTO students (student_no, student_code, prefix_th, first_name_th, last_name_th, class_id)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE student_no = VALUES(student_no), prefix_th = VALUES(prefix_th), first_name_th = VALUES(first_name_th), last_name_th = VALUES(last_name_th), class_id = VALUES(class_id), active = 1');
            $stmt->bind_param('issssi', $studentNo, $studentCode, $prefix, $firstName, $lastName, $classId);
        }
        $stmt->execute();
        flash('บันทึกข้อมูลนักเรียนแล้ว');
        redirect('admin.php?page=students&class_id=' . $classId);
    }

    if ($action === 'student_delete') {
        deny_unless_admin($isAdmin);
        $id = (int)arr_get($_POST, 'id', 0);
        $classId = (int)arr_get($_POST, 'class_id', 0);
        $stmt = db()->prepare('UPDATE students SET active = 0 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash('ลบข้อมูลนักเรียนแล้ว');
        redirect('admin.php?page=students&class_id=' . $classId);
    }

    if ($action === 'student_import') {
        deny_unless_admin($isAdmin);
        $classId = (int)$_POST['class_id'];
        $lines = preg_split('/\r\n|\r|\n/u', trim(arr_get($_POST, 'bulk_students', '')));
        $stmt = db()->prepare('INSERT INTO students (student_no, student_code, prefix_th, first_name_th, last_name_th, class_id)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE student_no = VALUES(student_no), prefix_th = VALUES(prefix_th), first_name_th = VALUES(first_name_th), last_name_th = VALUES(last_name_th), class_id = VALUES(class_id), active = 1');
        $count = 0;
        foreach ($lines as $line) {
            $cols = preg_split('/\t|,/u', trim($line));
            if (count($cols) < 5 || !is_numeric($cols[0])) {
                continue;
            }
            $studentNo = (int)$cols[0];
            $code = trim($cols[1]);
            $prefix = trim($cols[2]);
            $first = trim($cols[3]);
            $last = trim($cols[4]);
            $stmt->bind_param('issssi', $studentNo, $code, $prefix, $first, $last, $classId);
            $stmt->execute();
            $count++;
        }
        flash('นำเข้ารายชื่อนักเรียน ' . $count . ' คน');
        redirect('admin.php?page=students&class_id=' . $classId);
    }

    if ($action === 'student_import_excel') {
        deny_unless_admin($isAdmin);
        $defaultClassId = (int)arr_get($_POST, 'class_id', 0);
        try {
            $rows = uploaded_xlsx_rows('student_file');
            $stmt = db()->prepare('INSERT INTO students (student_no, student_code, prefix_th, first_name_th, last_name_th, class_id)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE student_no = VALUES(student_no), prefix_th = VALUES(prefix_th), first_name_th = VALUES(first_name_th), last_name_th = VALUES(last_name_th), class_id = VALUES(class_id), active = 1');
            $count = 0;
            $skipped = 0;
            foreach ($rows as $index => $row) {
                $studentNoText = normalize_excel_text(isset($row[0]) ? $row[0] : '');
                $studentCode = normalize_excel_text(isset($row[1]) ? $row[1] : '');
                $prefix = trim(isset($row[2]) ? $row[2] : '');
                $firstName = trim(isset($row[3]) ? $row[3] : '');
                $lastName = trim(isset($row[4]) ? $row[4] : '');
                if (is_student_import_header_row($studentNoText, $studentCode, $prefix, $firstName, $lastName)) {
                    continue;
                }
                if ($studentCode === '' || $firstName === '') {
                    $skipped++;
                    continue;
                }
                $rowClassIdRaw = normalize_excel_text(isset($row[5]) ? $row[5] : '');
                $classId = (is_numeric($rowClassIdRaw) && (int)$rowClassIdRaw > 0) ? (int)$rowClassIdRaw : $defaultClassId;
                if ($classId <= 0) {
                    $skipped++;
                    continue;
                }
                $studentNo = is_numeric($studentNoText) ? (int)$studentNoText : null;
                $stmt->bind_param('issssi', $studentNo, $studentCode, $prefix, $firstName, $lastName, $classId);
                $stmt->execute();
                $count++;
            }
            flash('นำเข้าข้อมูลนักเรียน ' . $count . ' คน' . ($skipped ? ' ข้าม ' . $skipped . ' รายการ' : ''));
        } catch (Exception $e) {
            flash($e->getMessage());
        }
        redirect('admin.php?page=import_students&class_id=' . $defaultClassId);
    }

    if ($action === 'schedule') {
        deny_unless_admin($isAdmin);
        $classId = (int)$_POST['class_id'];
        $teacherId = (int)$_POST['teacher_id'];
        $subjectId = (int)$_POST['subject_id'];
        $weekday = (int)$_POST['weekday'];
        $periodNo = (int)$_POST['period_no'];
        $start = $_POST['start_time'] ?: null;
        $end = $_POST['end_time'] ?: null;
        $room = trim(arr_get($_POST, 'room', ''));
        $academicYear = trim(arr_get($_POST, 'academic_year', ''));
        $term = trim(arr_get($_POST, 'term', ''));
        $stmt = db()->prepare('INSERT INTO schedule_slots (class_id, teacher_id, subject_id, weekday, period_no, start_time, end_time, room, academic_year, term)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiiiisssss', $classId, $teacherId, $subjectId, $weekday, $periodNo, $start, $end, $room, $academicYear, $term);
        $stmt->execute();
        flash('เพิ่มตารางสอนแล้ว');
        redirect('admin.php?page=schedules&class_id=' . $classId);
    }

    if ($action === 'user') {
        deny_unless_admin($isAdmin);
        $username = trim(arr_get($_POST, 'username', ''));
        $passwordHash = password_hash((string)$_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'] === 'admin' ? 'admin' : 'teacher';
        $teacherId = (int)arr_get($_POST, 'teacher_id', 0) ?: null;
        $userClassId = (int)arr_get($_POST, 'class_id', 0) ?: null;
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, role, teacher_id, class_id) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), teacher_id = VALUES(teacher_id), class_id = VALUES(class_id), active = 1');
        $stmt->bind_param('sssii', $username, $passwordHash, $role, $teacherId, $userClassId);
        $stmt->execute();
        flash('บันทึกผู้ใช้แล้ว');
        redirect('admin.php?page=users');
    }

    if ($action === 'user_password_reset') {
        deny_unless_admin($isAdmin);
        $targetUserId = (int)arr_get($_POST, 'user_id', 0);
        $newPassword = (string)arr_get($_POST, 'new_password', '');
        $confirmPassword = (string)arr_get($_POST, 'confirm_password', '');
        $targetUser = one_row('SELECT id, username FROM users WHERE id = ? AND role = "teacher" AND active = 1 LIMIT 1', 'i', [$targetUserId]);
        if (!$targetUser) {
            flash('ไม่พบบัญชีครูที่ต้องการเปลี่ยนรหัสผ่าน');
        } elseif (strlen($newPassword) < 6) {
            flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
        } elseif ($newPassword !== $confirmPassword) {
            flash('ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = "teacher"');
            $stmt->bind_param('si', $passwordHash, $targetUserId);
            $stmt->execute();
            flash('เปลี่ยนรหัสผ่านครู ' . $targetUser['username'] . ' เรียบร้อยแล้ว');
        }
        redirect('admin.php?page=users');
    }

    if ($action === 'class_teacher_password_reset') {
        deny_unless_admin($isAdmin);
        $targetClassId = (int)arr_get($_POST, 'class_id', 0);
        $newPassword = (string)arr_get($_POST, 'new_password', '');
        $confirmPassword = (string)arr_get($_POST, 'confirm_password', '');
        $targetUsers = all_rows(
            'SELECT u.id, u.username, c.class_name
             FROM users u
             JOIN classes c ON c.id = u.class_id
             WHERE u.class_id = ? AND u.role = "teacher" AND u.active = 1
             ORDER BY u.username',
            'i',
            [$targetClassId]
        );
        if (!$targetUsers) {
            flash('ไม่พบบัญชีครูประจำชั้นของห้องที่เลือก');
        } elseif (strlen($newPassword) < 6) {
            flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
        } elseif ($newPassword !== $confirmPassword) {
            flash('ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE class_id = ? AND role = "teacher" AND active = 1');
            $stmt->bind_param('si', $passwordHash, $targetClassId);
            $stmt->execute();
            $usernames = [];
            foreach ($targetUsers as $targetUser) {
                $usernames[] = $targetUser['username'];
            }
            flash('เปลี่ยนรหัสผ่านครูประจำชั้น ' . $targetUsers[0]['class_name'] . ' (' . implode(', ', $usernames) . ') เรียบร้อยแล้ว');
        }
        redirect('admin.php?page=users');
    }

    if ($action === 'attendance') {
        $classId = (int)$_POST['class_id'];
        enforce_class_access($user, $classId);
        $teacherId = (int)arr_get($_POST, 'teacher_id', 0) ?: null;
        $subjectId = (int)arr_get($_POST, 'subject_id', 0) ?: null;
        $slotId = (int)arr_get($_POST, 'schedule_slot_id', 0) ?: null;
        if ($slotId) {
            $slotClass = one_row('SELECT class_id FROM schedule_slots WHERE id = ? LIMIT 1', 'i', [$slotId]);
            if (!$slotClass || (int)$slotClass['class_id'] !== $classId) {
                $slotId = null;
            }
        }
        $homeroomTeachers = homeroom_teachers_for_class($classId);
        if (!$homeroomTeachers) {
            http_response_code(400);
            exit('ยังไม่ได้กำหนดครูประจำชั้นสำหรับห้องนี้');
        }
        if (!$teacherId) {
            $teacherId = (int)$homeroomTeachers[0]['id'];
        } elseif (!teacher_id_is_allowed($teacherId, $homeroomTeachers)) {
            http_response_code(400);
            exit('ครูผู้เช็คต้องเป็นครูประจำชั้นของห้องนี้เท่านั้น');
        }
        $date = $_POST['attendance_date'];
        $academicYear = trim(arr_get($_POST, 'academic_year', ''));
        $term = trim(arr_get($_POST, 'term', ''));
        $roomNote = trim(arr_get($_POST, 'room_note', ''));
        $existingAttendance = one_row('SELECT id FROM attendance WHERE class_id = ? AND attendance_date = ? AND subject_id <=> ? AND schedule_slot_id <=> ? ORDER BY id LIMIT 1', 'isii', [$classId, $date, $subjectId, $slotId]);
        if ($existingAttendance) {
            $attendanceId = (int)$existingAttendance['id'];
            $stmt = db()->prepare('UPDATE attendance SET teacher_id = ?, academic_year = ?, term = ?, room_note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->bind_param('isssi', $teacherId, $academicYear, $term, $roomNote, $attendanceId);
            $stmt->execute();
        } else {
            $stmt = db()->prepare('INSERT INTO attendance (class_id, teacher_id, subject_id, schedule_slot_id, attendance_date, academic_year, term, room_note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iiiissssi', $classId, $teacherId, $subjectId, $slotId, $date, $academicYear, $term, $roomNote, $user['id']);
            $stmt->execute();
            $attendanceId = (int)db()->insert_id;
        }
        $item = db()->prepare('INSERT INTO attendance_items (attendance_id, student_id, status, note)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)');
        foreach (arr_get($_POST, 'status', []) as $studentId => $status) {
            $note = trim(isset($_POST['note'][$studentId]) ? $_POST['note'][$studentId] : '');
            $sid = (int)$studentId;
            $item->bind_param('iiss', $attendanceId, $sid, $status, $note);
            $item->execute();
        }
        flash('บันทึกเช็คชื่อแล้ว');
        redirect('admin.php?page=check&class_id=' . $classId . '&date=' . urlencode($date) . '&slot_id=' . (int)$slotId . '&subject_id=' . (int)$subjectId);
    }

    if ($action === 'attendance_delete') {
        $attendanceId = (int)arr_get($_POST, 'attendance_id', 0);
        $classId = (int)arr_get($_POST, 'class_id', 0);
        enforce_class_access($user, $classId);
        $slotId = (int)arr_get($_POST, 'schedule_slot_id', 0);
        $subjectId = (int)arr_get($_POST, 'subject_id', 0);
        $date = arr_get($_POST, 'attendance_date', date('Y-m-d'));
        $returnPage = arr_get($_POST, 'return_page', 'check');
        if ($attendanceId > 0) {
            $stmt = db()->prepare('DELETE FROM attendance_items WHERE attendance_id = ?');
            $stmt->bind_param('i', $attendanceId);
            $stmt->execute();

            $stmt = db()->prepare('DELETE FROM attendance WHERE id = ? AND class_id = ? AND attendance_date = ?');
            $stmt->bind_param('iis', $attendanceId, $classId, $date);
            $stmt->execute();
            flash('ล้างข้อมูลเช็คชื่อแล้ว');
        }
        if ($returnPage === 'reports') {
            redirect('admin.php?page=reports&class_id=' . $classId);
        }
        redirect('admin.php?page=check&class_id=' . $classId . '&date=' . urlencode($date) . '&slot_id=' . (int)$slotId . '&subject_id=' . (int)$subjectId);
    }
}

$teachers = all_rows('SELECT * FROM teachers WHERE active = 1 ORDER BY name_th');
$homeroomTeacherSql = homeroom_teacher_sql('c', 't');
$classSortSql = class_sort_sql('c');
$classWhere = 'WHERE c.active = 1';
$classTypes = '';
$classParams = [];
if (!$isAdmin && (int)arr_get($user, 'class_id', 0) > 0) {
    $classWhere .= ' AND c.id = ?';
    $classTypes = 'i';
    $classParams[] = (int)$user['class_id'];
}
$classes = all_rows('SELECT c.*, ' . $homeroomTeacherSql . ' AS teacher_name FROM classes c LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id ' . $classWhere . ' ORDER BY ' . $classSortSql, $classTypes, $classParams);
$subjects = all_rows('SELECT * FROM subjects WHERE active = 1 ORDER BY subject_code');
$classId = (int)arr_get($_GET, 'class_id', (isset($classes[0]['id']) ? $classes[0]['id'] : 0));
if (!$isAdmin && (int)arr_get($user, 'class_id', 0) > 0) {
    $classId = (int)$user['class_id'];
}
enforce_class_access($user, $classId);
$date = arr_get($_GET, 'date', date('Y-m-d'));
$statuses = attendance_statuses();

layout_header('หลังบ้าน');
flash_html();
?>
<nav class="tabs">
    <?php
    $tabs = ['check' => 'เช็คชื่อ'];
    foreach ($tabs as $key => $label):
    ?>
        <a class="<?= $page === $key ? 'active' : '' ?>" href="admin.php?page=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($page === 'settings' && $isAdmin): ?>
    <?php
    $settingMenus = [
        ['page' => 'reports', 'label' => 'รายงาน'],
        ['page' => 'settings', 'label' => 'ตั้งค่า'],
        ['page' => 'students', 'label' => 'นักเรียน'],
        ['page' => 'classes', 'label' => 'ห้องเรียน'],
        ['page' => 'teachers', 'label' => 'ครู'],
        ['page' => 'import_teachers', 'label' => 'นำเข้าข้อมูลครู'],
        ['page' => 'subjects', 'label' => 'รายวิชา'],
        ['page' => 'schedules', 'label' => 'ตารางสอน'],
        ['page' => 'users', 'label' => 'ผู้ใช้'],
        ['page' => 'import_students', 'label' => 'นำเข้าข้อมูลนักเรียน'],
    ];
    ?>
    <section class="panel settings-menu-panel">
        <h1>ตั้งค่า</h1>
        <div class="settings-menu-grid">
            <?php foreach ($settingMenus as $item): ?>
                <a class="<?= $page === $item['page'] ? 'active' : '' ?>" href="admin.php?page=<?= e($item['page']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <h1>ตั้งค่าระบบ</h1>
        <form method="post" class="form-grid" enctype="multipart/form-data">
            <input type="hidden" name="action" value="settings">
            <label>ชื่อโรงเรียน <input name="school_name" value="<?= e($settings['school_name']) ?>" required></label>
            <label>ปีการศึกษา <input name="academic_year" value="<?= e($settings['academic_year']) ?>" required></label>
            <label>ภาคเรียน <input name="term" value="<?= e($settings['term']) ?>" required></label>
            <label>เปลี่ยนโลโก้ <input name="logo_file" type="file" accept="image/png,image/jpeg,image/gif,image/webp"></label>
            <div class="logo-setting-preview">
                <span>โลโก้ปัจจุบัน</span>
                <img src="<?= e($settings['logo_path']) ?>" alt="<?= e($settings['school_name']) ?>">
            </div>
            <button class="primary">บันทึก</button>
        </form>
        <p class="hint">รองรับไฟล์ PNG, JPG, GIF, WEBP ขนาดต้นฉบับไม่เกิน 15 MB และจะย่อ/บีบอัดไฟล์ที่ใหญ่เกิน 1 MB ให้อัตโนมัติ หากไม่เลือกไฟล์ใหม่ ระบบจะใช้โลโก้เดิม</p>
    </section>

<?php elseif ($page === 'teachers' && $isAdmin): ?>
    <?php
    $editTeacher = null;
    $editTeacherId = (int)arr_get($_GET, 'edit_id', 0);
    if ($editTeacherId > 0) {
        $editTeacher = one_row('SELECT * FROM teachers WHERE id = ? LIMIT 1', 'i', [$editTeacherId]);
    }
    ?>
    <section class="panel">
        <h1>ครู</h1>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="teacher">
            <input type="hidden" name="id" value="<?= e($editTeacher ? (string)$editTeacher['id'] : '0') ?>">
            <label>รหัสครู <input name="teacher_code" value="<?= e($editTeacher ? $editTeacher['teacher_code'] : '') ?>"></label>
            <label>ชื่อครู <input name="name_th" value="<?= e($editTeacher ? $editTeacher['name_th'] : '') ?>" required></label>
            <label>โทรศัพท์ <input name="phone" value="<?= e($editTeacher ? $editTeacher['phone'] : '') ?>"></label>
            <button class="primary"><?= $editTeacher ? 'บันทึกการแก้ไข' : 'เพิ่มครู' ?></button>
            <?php if ($editTeacher): ?><a class="button" href="admin.php?page=teachers">ยกเลิก</a><?php endif; ?>
        </form>
        <div class="table-wrap"><table><thead><tr><th>รหัส</th><th>ชื่อ</th><th>โทรศัพท์</th><th>จัดการ</th></tr></thead><tbody>
        <?php foreach ($teachers as $teacher): ?>
            <tr>
                <td><?= e($teacher['teacher_code']) ?></td>
                <td><?= e($teacher['name_th']) ?></td>
                <td><?= e($teacher['phone']) ?></td>
                <td class="actions">
                    <a class="button" href="admin.php?page=teachers&edit_id=<?= (int)$teacher['id'] ?>">แก้ไข</a>
                    <form method="post" onsubmit="return confirm('ต้องการลบข้อมูลครูนี้หรือไม่?');">
                        <input type="hidden" name="action" value="teacher_delete">
                        <input type="hidden" name="id" value="<?= (int)$teacher['id'] ?>">
                        <button class="danger">ลบ</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'import_teachers' && $isAdmin): ?>
    <section class="panel">
        <h1>นำเข้าข้อมูลครู</h1>
        <form method="post" class="form-grid" enctype="multipart/form-data">
            <input type="hidden" name="action" value="teacher_import_excel">
            <label>ไฟล์ Excel (.xlsx)
                <input name="teacher_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </label>
            <button class="primary">นำเข้าข้อมูลครู</button>
            <a class="button btn-outline" href="admin.php?page=import_teachers&download_template=teachers">ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <a class="button btn-outline" href="admin.php?page=settings">กลับหน้าตั้งค่า</a>
        </form>
        <p class="hint">ใช้ sheet แรกของไฟล์ Excel คอลัมน์ A-C: รหัสครู, ชื่อครู, โทรศัพท์ แถวแรกเป็นหัวตารางได้</p>
        <div class="table-wrap"><table><thead><tr><th>A</th><th>B</th><th>C</th></tr></thead><tbody>
            <tr><td>รหัสครู</td><td>ชื่อครู</td><td>โทรศัพท์</td></tr>
            <tr><td>T101</td><td>ครูตัวอย่าง หนึ่ง</td><td>0800000001</td></tr>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'classes' && $isAdmin): ?>
    <?php
    $editClass = null;
    $editClassId = (int)arr_get($_GET, 'edit_id', 0);
    if ($editClassId > 0) {
        $editClass = one_row('SELECT * FROM classes WHERE id = ? LIMIT 1', 'i', [$editClassId]);
    }
    ?>
    <section class="panel">
        <h1>ห้องเรียน</h1>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="<?= $editClass ? 'class_update' : 'class' ?>">
            <?php if ($editClass): ?><input type="hidden" name="id" value="<?= (int)$editClass['id'] ?>"><?php endif; ?>
            <label>ห้องเรียน <input name="class_name" value="<?= e($editClass ? $editClass['class_name'] : '') ?>" placeholder="ม.1.1" required></label>
            <label>ห้องประจำ <input name="room_no" value="<?= e($editClass ? (string)$editClass['room_no'] : '') ?>" placeholder="441"></label>
            <label>ครูประจำชั้น
                <select name="homeroom_teacher_id">
                    <option value="">-</option>
                    <?= options_html($teachers, 'id', 'name_th', $editClass ? (string)$editClass['homeroom_teacher_id'] : '') ?>
                </select>
            </label>
            <button class="primary"><?= $editClass ? 'บันทึกการแก้ไข' : 'เพิ่มห้อง' ?></button>
            <?php if ($editClass): ?><a class="button" href="admin.php?page=classes">ยกเลิก</a><?php endif; ?>
        </form>
        <div class="table-wrap"><table><thead><tr><th>รหัส (class_id)</th><th>ห้อง</th><th>ห้องประจำ</th><th>ครูประจำชั้น</th><th>จัดการ</th></tr></thead><tbody>
        <?php foreach ($classes as $class): ?>
            <tr>
                <td><?= (int)$class['id'] ?></td>
                <td><?= e($class['class_name']) ?></td>
                <td><?= e((string)$class['room_no']) ?></td>
                <td><?= e($class['teacher_name']) ?></td>
                <td class="actions">
                    <a class="button" href="admin.php?page=classes&edit_id=<?= (int)$class['id'] ?>">แก้ไข</a>
                    <form method="post" onsubmit="return confirm('ต้องการลบห้อง <?= e($class['class_name']) ?> หรือไม่?');">
                        <input type="hidden" name="action" value="class_delete">
                        <input type="hidden" name="id" value="<?= (int)$class['id'] ?>">
                        <button class="danger">ลบ</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'subjects' && $isAdmin): ?>
    <?php
    $editSubject = null;
    $editSubjectId = (int)arr_get($_GET, 'edit_id', 0);
    if ($editSubjectId > 0) {
        $editSubject = one_row('SELECT * FROM subjects WHERE id = ? LIMIT 1', 'i', [$editSubjectId]);
    }
    ?>
    <section class="panel">
        <h1>รายวิชา</h1>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="subject">
            <input type="hidden" name="id" value="<?= e($editSubject ? (string)$editSubject['id'] : '0') ?>">
            <label>รหัสวิชา <input name="subject_code" value="<?= e($editSubject ? $editSubject['subject_code'] : '') ?>" required></label>
            <label>ชื่อวิชา <input name="subject_name" value="<?= e($editSubject ? $editSubject['subject_name'] : '') ?>" required></label>
            <button class="primary"><?= $editSubject ? 'บันทึกการแก้ไข' : 'เพิ่มรายวิชา' ?></button>
            <?php if ($editSubject): ?><a class="button" href="admin.php?page=subjects">ยกเลิก</a><?php endif; ?>
        </form>
        <div class="table-wrap"><table><thead><tr><th>รหัสวิชา</th><th>ชื่อวิชา</th><th>จัดการ</th></tr></thead><tbody>
        <?php foreach ($subjects as $subject): ?>
            <tr>
                <td><?= e($subject['subject_code']) ?></td>
                <td><?= e($subject['subject_name']) ?></td>
                <td class="actions">
                    <a class="button" href="admin.php?page=subjects&edit_id=<?= (int)$subject['id'] ?>">แก้ไข</a>
                    <form method="post" onsubmit="return confirm('ต้องการลบรายวิชานี้หรือไม่?');">
                        <input type="hidden" name="action" value="subject_delete">
                        <input type="hidden" name="id" value="<?= (int)$subject['id'] ?>">
                        <button class="danger">ลบ</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'students' && $isAdmin): ?>
    <?php
    $editStudent = null;
    $editStudentId = (int)arr_get($_GET, 'edit_id', 0);
    if ($editStudentId > 0) {
        $editStudent = one_row('SELECT * FROM students WHERE id = ? AND active = 1 LIMIT 1', 'i', [$editStudentId]);
        if ($editStudent) {
            $classId = (int)$editStudent['class_id'];
        }
    }
    $students = $classId ? all_rows('SELECT * FROM students WHERE class_id = ? AND active = 1 ORDER BY student_no, student_code', 'i', [$classId]) : [];
    ?>
    <section class="panel">
        <h1>นักเรียน</h1>
        <form class="inline-form" method="get">
            <input type="hidden" name="page" value="students">
            <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <button>เลือก</button>
        </form>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="student">
            <input type="hidden" name="id" value="<?= e($editStudent ? (string)$editStudent['id'] : '0') ?>">
            <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <label>เลขที่ <input name="student_no" type="number" value="<?= e($editStudent ? (string)$editStudent['student_no'] : '') ?>"></label>
            <label>เลขประจำตัว <input name="student_code" value="<?= e($editStudent ? $editStudent['student_code'] : '') ?>" required></label>
            <label>คำนำหน้า <input name="prefix_th" value="<?= e($editStudent ? $editStudent['prefix_th'] : 'เด็กชาย') ?>"></label>
            <label>ชื่อ <input name="first_name_th" value="<?= e($editStudent ? $editStudent['first_name_th'] : '') ?>" required></label>
            <label>สกุล <input name="last_name_th" value="<?= e($editStudent ? $editStudent['last_name_th'] : '') ?>" required></label>
            <button class="primary"><?= $editStudent ? 'บันทึกการแก้ไข' : 'เพิ่มนักเรียน' ?></button>
            <?php if ($editStudent): ?><a class="button" href="admin.php?page=students&class_id=<?= $classId ?>">ยกเลิก</a><?php endif; ?>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="student_import">
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <label>นำเข้าจาก Excel: คัดลอกคอลัมน์ เลขที่, เลขประจำตัว, คำนำหน้า, ชื่อ, สกุล แล้ววางที่นี่
                <textarea name="bulk_students" rows="5"></textarea>
            </label>
            <button class="primary">นำเข้ารายชื่อ</button>
        </form>
        <div class="table-wrap"><table><thead><tr><th>เลขที่</th><th>เลขประจำตัว</th><th>ชื่อ - สกุล</th><th>จัดการ</th></tr></thead><tbody>
        <?php foreach ($students as $student): ?>
            <tr>
                <td><?= e((string)$student['student_no']) ?></td>
                <td><?= e($student['student_code']) ?></td>
                <td><?= e(trim($student['prefix_th'] . ' ' . $student['first_name_th'] . ' ' . $student['last_name_th'])) ?></td>
                <td class="actions">
                    <a class="button" href="admin.php?page=students&class_id=<?= $classId ?>&edit_id=<?= (int)$student['id'] ?>">แก้ไข</a>
                    <form method="post" onsubmit="return confirm('ต้องการลบข้อมูลนักเรียนนี้หรือไม่?');">
                        <input type="hidden" name="action" value="student_delete">
                        <input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                        <button class="danger">ลบ</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'import_students' && $isAdmin): ?>
    <section class="panel">
        <h1>นำเข้าข้อมูลนักเรียน</h1>
        <form method="post" class="form-grid" enctype="multipart/form-data">
            <input type="hidden" name="action" value="student_import_excel">
            <label>ห้องเรียน
                <select name="class_id" required><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select>
            </label>
            <label>ไฟล์ Excel (.xlsx)
                <input name="student_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </label>
            <button class="primary">นำเข้าข้อมูลนักเรียน</button>
            <a class="button btn-outline" href="admin.php?page=import_students&download_template=students">ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <a class="button btn-outline" href="admin.php?page=settings">กลับหน้าตั้งค่า</a>
        </form>
        <p class="hint">ใช้ sheet แรกของไฟล์ Excel คอลัมน์ A-F: เลขที่, รหัสนักเรียน, คำนำหน้า, ชื่อ, นามสกุล, class_id (ห้อง) — ดู class_id ได้จาก sheet "อ้างอิงห้อง" ในไฟล์ตัวอย่าง ถ้าไม่ระบุ class_id ในไฟล์จะใช้ห้องที่เลือกด้านบน</p>
        <div class="table-wrap"><table><thead><tr><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th><th>F</th></tr></thead><tbody>
            <tr><td>เลขที่</td><td>รหัสนักเรียน</td><td>คำนำหน้า</td><td>ชื่อ</td><td>นามสกุล</td><td>class_id (ห้อง)</td></tr>
            <tr><td>1</td><td>12345</td><td>เด็กชาย</td><td>สมชาย</td><td>ใจดี</td><td>1</td></tr>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'schedules' && $isAdmin): ?>
    <?php $slots = $classId ? all_rows('SELECT s.*, sub.subject_code, sub.subject_name, t.name_th AS teacher_name FROM schedule_slots s JOIN subjects sub ON sub.id=s.subject_id JOIN teachers t ON t.id=s.teacher_id WHERE s.class_id=? ORDER BY s.weekday, s.period_no', 'i', [$classId]) : []; ?>
    <section class="panel">
        <h1>ตารางสอน</h1>
        <form class="inline-form" method="get"><input type="hidden" name="page" value="schedules"><label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label><button>เลือก</button></form>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="schedule">
            <input type="hidden" name="academic_year" value="<?= e($settings['academic_year']) ?>">
            <input type="hidden" name="term" value="<?= e($settings['term']) ?>">
            <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <label>ครู <select name="teacher_id" required><?= options_html($teachers, 'id', 'name_th') ?></select></label>
            <label>วิชา <select name="subject_id" required><?= options_html($subjects, 'id', 'subject_name') ?></select></label>
            <label>วัน <select name="weekday"><option value="1">จันทร์</option><option value="2">อังคาร</option><option value="3">พุธ</option><option value="4">พฤหัสฯ</option><option value="5">ศุกร์</option></select></label>
            <label>คาบ <input name="period_no" type="number" min="0" max="10" required></label>
            <label>เริ่ม <input name="start_time" type="time"></label>
            <label>สิ้นสุด <input name="end_time" type="time"></label>
            <label>ห้อง <input name="room"></label>
            <button class="primary">เพิ่มตาราง</button>
        </form>
        <div class="table-wrap"><table><thead><tr><th>วัน</th><th>คาบ</th><th>เวลา</th><th>วิชา</th><th>ครู</th><th>ห้อง</th></tr></thead><tbody>
        <?php foreach ($slots as $slot): ?><tr><td><?= e(weekday_name($slot['weekday'])) ?></td><td><?= e((string)$slot['period_no']) ?></td><td><?= e(substr((string)$slot['start_time'],0,5) . '-' . substr((string)$slot['end_time'],0,5)) ?></td><td><?= e($slot['subject_code'] . ' ' . $slot['subject_name']) ?></td><td><?= e($slot['teacher_name']) ?></td><td><?= e($slot['room']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'users' && $isAdmin): ?>
    <?php
    $users = all_rows(
        'SELECT u.*, c.class_name,
                COALESCE((
                    SELECT GROUP_CONCAT(t2.name_th ORDER BY cht.sort_order SEPARATOR ", ")
                    FROM class_homeroom_teachers cht
                    JOIN teachers t2 ON t2.id = cht.teacher_id
                    WHERE cht.class_id = u.class_id
                ), t.name_th) AS teacher_name
         FROM users u
         LEFT JOIN teachers t ON t.id=u.teacher_id
         LEFT JOIN classes c ON c.id=u.class_id
         ORDER BY u.role, u.username'
    );
    $teacherClassUsers = all_rows(
        'SELECT u.class_id AS id,
                CONCAT(c.class_name, " - ", u.username, " - ", COALESCE((
                    SELECT GROUP_CONCAT(t2.name_th ORDER BY cht.sort_order SEPARATOR ", ")
                    FROM class_homeroom_teachers cht
                    JOIN teachers t2 ON t2.id = cht.teacher_id
                    WHERE cht.class_id = u.class_id
                ), "")) AS label
         FROM users u
         JOIN classes c ON c.id = u.class_id
         WHERE u.role = "teacher" AND u.active = 1 AND u.class_id IS NOT NULL
         ORDER BY ' . class_sort_sql('c') . ', u.username'
    );
    ?>
    <section class="panel">
        <h1>ผู้ใช้ระบบ</h1>
        <div class="notice">ห้องที่มีครูประจำชั้น 2 คน สามารถใช้ชื่อผู้ใช้เดียวกันของห้องได้ เช่น ม.1.2 ใช้ m102 ร่วมกัน</div>
        <h2>เปลี่ยนรหัสผ่านครูตามห้องเรียน</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="class_teacher_password_reset">
            <label>ห้องเรียน / ชื่อผู้ใช้
                <select name="class_id" required><?= options_html($teacherClassUsers, 'id', 'label') ?></select>
            </label>
            <label>รหัสผ่านใหม่ <input name="new_password" type="password" minlength="6" required></label>
            <label>ยืนยันรหัสผ่านใหม่ <input name="confirm_password" type="password" minlength="6" required></label>
            <button class="primary">เปลี่ยนรหัสผ่านตามห้อง</button>
        </form>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="user">
            <label>ชื่อผู้ใช้ <input name="username" required></label>
            <label>รหัสผ่าน <input name="password" type="password" required></label>
            <label>สิทธิ์ <select name="role"><option value="teacher">ครู</option><option value="admin">แอดมิน</option></select></label>
            <label>ผูกกับครู <select name="teacher_id"><option value="">-</option><?= options_html($teachers, 'id', 'name_th') ?></select></label>
            <label>ผูกกับห้องเรียน <select name="class_id"><option value="">-</option><?= options_html($classes, 'id', 'class_name') ?></select></label>
            <button class="primary">บันทึกผู้ใช้</button>
        </form>
        <div class="table-wrap"><table><thead><tr><th>ชื่อผู้ใช้</th><th>สิทธิ์</th><th>ครู</th><th>ห้องเรียน</th><th>ตั้งรหัสครู</th></tr></thead><tbody>
        <?php foreach ($users as $row): ?>
            <tr>
                <td><?= e($row['username']) ?></td>
                <td><?= e($row['role']) ?></td>
                <td><?= e($row['teacher_name']) ?></td>
                <td><?= e($row['class_name']) ?></td>
                <td>
                    <?php if ($row['role'] === 'teacher'): ?>
                        <form method="post" class="inline-form compact-password-form">
                            <input type="hidden" name="action" value="user_password_reset">
                            <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                            <label>รหัสใหม่ <input type="password" name="new_password" minlength="6" required></label>
                            <label>ยืนยัน <input type="password" name="confirm_password" minlength="6" required></label>
                            <button class="primary">เปลี่ยนรหัส</button>
                        </form>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

<?php elseif ($page === 'reports'): ?>
    <?php
    $reportSlots = $classId ? all_rows('SELECT s.*, sub.subject_code, sub.subject_name, t.name_th AS teacher_name FROM schedule_slots s JOIN subjects sub ON sub.id=s.subject_id JOIN teachers t ON t.id=s.teacher_id WHERE s.class_id=? AND s.academic_year=? AND s.term=? ORDER BY s.weekday, s.period_no', 'iss', [$classId, $settings['academic_year'], $settings['term']]) : [];
    $reportSlotOptions = [];
    foreach ($reportSlots as $slot) {
        $slot['label'] = $slot['subject_code'] . ' คาบ ' . $slot['period_no'] . ' ' . $slot['teacher_name'];
        $reportSlotOptions[] = $slot;
    }
    $reports = $classId ? all_rows(
        'SELECT a.id, a.attendance_date, a.class_id, a.subject_id, a.schedule_slot_id, a.room_note, c.class_name,
            sub.subject_code, sub.subject_name, t.name_th AS teacher_name,
            COUNT(ai.id) AS total,
            SUM(ai.status = "present") AS present_total,
            SUM(ai.status = "absent") AS absent_total,
            SUM(ai.status = "leave") AS leave_total,
            SUM(ai.status = "late") AS late_total,
            SUM(ai.status = "activity") AS activity_total
         FROM attendance a
         JOIN classes c ON c.id=a.class_id
         LEFT JOIN subjects sub ON sub.id=a.subject_id
         LEFT JOIN teachers t ON t.id=a.teacher_id
         LEFT JOIN attendance_items ai ON ai.attendance_id=a.id
         WHERE a.class_id=?
         GROUP BY a.id, a.attendance_date, a.class_id, a.subject_id, a.schedule_slot_id, a.room_note, c.class_name, sub.subject_code, sub.subject_name, t.name_th
         ORDER BY a.attendance_date DESC, a.id DESC',
        'i',
        [$classId]
    ) : [];
    ?>
    <section class="panel">
        <h1>รายงานเช็คชื่อ</h1>
        <form class="inline-form" method="get">
            <input type="hidden" name="page" value="reports">
            <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <button>ดูรายงาน</button>
        </form>
        <form class="inline-form report-create" method="get">
            <input type="hidden" name="page" value="check">
            <label>วันที่ <input type="date" name="date" value="<?= e($date) ?>"></label>
            <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <button class="primary">เพิ่มรายงาน</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>วันที่</th><th>ห้อง</th><th>วิชา</th><th>ครู</th><th>ทั้งหมด</th><th>มา</th><th>ขาด</th><th>ลา</th><th>สาย</th><th>กิจกรรม</th><th>หมายเหตุทั้งห้อง</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $row): ?>
                    <?php
                    $editUrl = 'admin.php?page=check&date=' . urlencode($row['attendance_date']) . '&class_id=' . (int)$row['class_id'] . '&slot_id=' . (int)arr_get($row, 'schedule_slot_id', 0) . '&subject_id=' . (int)arr_get($row, 'subject_id', 0);
                    ?>
                    <tr>
                        <td><?= e(thai_date($row['attendance_date'])) ?></td>
                        <td><?= e($row['class_name']) ?></td>
                        <td><?= e(trim(arr_get($row, 'subject_code', '') . ' ' . arr_get($row, 'subject_name', ''))) ?></td>
                        <td><?= e($row['teacher_name']) ?></td>
                        <td><?= (int)$row['total'] ?></td>
                        <td><?= (int)$row['present_total'] ?></td>
                        <td><?= (int)$row['absent_total'] ?></td>
                        <td><?= (int)$row['leave_total'] ?></td>
                        <td><?= (int)$row['late_total'] ?></td>
                        <td><?= (int)$row['activity_total'] ?></td>
                        <td><?= e(arr_get($row, 'room_note', '') ?: '-') ?></td>
                        <td class="actions">
                            <a class="button" href="<?= e($editUrl) ?>">แก้ไข</a>
                            <form method="post" onsubmit="return confirm('ต้องการล้างข้อมูลการเช็คชื่อนี้หรือไม่?');">
                                <input type="hidden" name="action" value="attendance_delete">
                                <input type="hidden" name="return_page" value="reports">
                                <input type="hidden" name="attendance_id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="attendance_date" value="<?= e($row['attendance_date']) ?>">
                                <input type="hidden" name="class_id" value="<?= (int)$row['class_id'] ?>">
                                <input type="hidden" name="schedule_slot_id" value="<?= (int)arr_get($row, 'schedule_slot_id', 0) ?>">
                                <input type="hidden" name="subject_id" value="<?= (int)arr_get($row, 'subject_id', 0) ?>">
                                <button class="danger">ล้างข้อมูล</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?><tr><td colspan="12">ไม่พบข้อมูลรายงาน</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <?php
    $slots = $classId ? all_rows('SELECT s.*, sub.subject_code, sub.subject_name, t.name_th AS teacher_name FROM schedule_slots s JOIN subjects sub ON sub.id=s.subject_id JOIN teachers t ON t.id=s.teacher_id WHERE s.class_id=? AND s.academic_year=? AND s.term=? ORDER BY s.weekday, s.period_no', 'iss', [$classId, $settings['academic_year'], $settings['term']]) : [];
    $students = $classId ? all_rows('SELECT * FROM students WHERE class_id=? AND active=1 ORDER BY student_no, student_code', 'i', [$classId]) : [];
    $slotId = (int)arr_get($_GET, 'slot_id', 0);
    $selectedSlot = $slotId ? one_row('SELECT * FROM schedule_slots WHERE id=? AND class_id=?', 'ii', [$slotId, $classId]) : null;
    if (!$selectedSlot) {
        $slotId = 0;
    }
    $subjectId = (int)(isset($selectedSlot['subject_id']) ? $selectedSlot['subject_id'] : arr_get($_GET, 'subject_id', (isset($subjects[0]['id']) ? $subjects[0]['id'] : 0)));
    $homeroomTeachers = homeroom_teachers_for_class($classId);
    $existing = one_row('SELECT id, teacher_id, room_note FROM attendance WHERE class_id=? AND attendance_date=? AND subject_id <=> ? AND schedule_slot_id <=> ? ORDER BY id LIMIT 1', 'isii', [$classId, $date, $subjectId ?: null, $slotId ?: null]);
    $roomNote = arr_get($existing ?: [], 'room_note', '');
    $preferredTeacherId = (int)arr_get($existing ?: [], 'teacher_id', 0);
    if (!$preferredTeacherId) {
        $preferredTeacherId = (int)arr_get($user, 'teacher_id', 0);
    }
    $teacherId = teacher_id_is_allowed($preferredTeacherId, $homeroomTeachers)
        ? $preferredTeacherId
        : (isset($homeroomTeachers[0]['id']) ? (int)$homeroomTeachers[0]['id'] : 0);
    $items = $existing ? all_rows('SELECT * FROM attendance_items WHERE attendance_id=?', 'i', [(int)$existing['id']]) : [];
    $isAttendanceSaved = $existing && count($items) > 0;
    $itemMap = [];
    foreach ($items as $item) { $itemMap[(int)$item['student_id']] = $item; }
    $slotOptions = [];
    foreach ($slots as $slot) {
        $slot['label'] = $slot['subject_code'] . ' คาบ ' . $slot['period_no'] . ' ' . $slot['teacher_name'];
        $slotOptions[] = $slot;
    }
    $selectedClass = null;
    foreach ($classes as $class) {
        if ((int)$class['id'] === $classId) {
            $selectedClass = $class;
            break;
        }
    }
    $hasLoadedStudents = isset($_GET['date'], $_GET['class_id']);
    ?>
    <section class="panel">
        <h1 class="check-heading">
            เช็คชื่อนักเรียน
            <?php if ($isAttendanceSaved): ?>
                <span class="heading-status saved">บันทึกข้อมูลเรียบร้อย</span>
            <?php else: ?>
                <span class="heading-status pending">ยังไม่บันทึก</span>
            <?php endif; ?>
        </h1>
        <form class="inline-form" method="get">
            <input type="hidden" name="page" value="check">
            <label>วันที่ <input type="date" name="date" value="<?= e($date) ?>"></label>
            <?php if ($isAdmin): ?>
                <label>ห้องเรียน <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select></label>
            <?php else: ?>
                <input type="hidden" name="class_id" value="<?= (int)$classId ?>">
            <?php endif; ?>
            <button>โหลดรายชื่อ</button>
        </form>
        <?php if ($hasLoadedStudents): ?>
            <div class="check-context">
                <span>ห้องเรียน: <strong><?= e(isset($selectedClass['class_name']) ? $selectedClass['class_name'] : '-') ?></strong></span>
                <span>วันที่: <strong><?= e(thai_date($date)) ?></strong></span>
                <span>จำนวนนักเรียน: <strong><?= count($students) ?></strong> คน</span>
            </div>
        <?php endif; ?>
        <?php if ($hasLoadedStudents): ?>
            <form method="post">
                <input type="hidden" name="action" value="attendance">
                <input type="hidden" name="academic_year" value="<?= e($settings['academic_year']) ?>">
                <input type="hidden" name="term" value="<?= e($settings['term']) ?>">
                <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
                <input type="hidden" name="class_id" value="<?= $classId ?>">
                <input type="hidden" name="schedule_slot_id" value="<?= $slotId ?>">
                <div class="form-grid">
                    <label>ครูผู้เช็ค <select name="teacher_id" required>
                        <?php if ($homeroomTeachers): ?>
                            <?= options_html($homeroomTeachers, 'id', 'name_th', (string)$teacherId) ?>
                        <?php else: ?>
                            <option value="">ยังไม่ได้กำหนดครูประจำชั้น</option>
                        <?php endif; ?>
                    </select></label>
                    <label>รายวิชา <select name="subject_id"><?= options_html($subjects, 'id', 'subject_name', (string)$subjectId) ?></select></label>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>เลขที่</th><th>เลขประจำตัว</th><th>ชื่อ - สกุล</th><th>สถานะ</th><th>หมายเหตุ</th></tr></thead>
                        <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php $saved = isset($itemMap[(int)$student['id']]) ? $itemMap[(int)$student['id']] : ['status' => 'present', 'note' => '']; ?>
                            <tr>
                                <td><?= e((string)$student['student_no']) ?></td>
                                <td><?= e($student['student_code']) ?></td>
                                <td>
                                    <?= e(trim($student['prefix_th'] . ' ' . $student['first_name_th'] . ' ' . $student['last_name_th'])) ?>
                                    <?php if ($isAttendanceSaved && isset($itemMap[(int)$student['id']])): ?>
                                        <span class="saved-status"><?= e(isset($statuses[$saved['status']]) ? $statuses[$saved['status']] : $saved['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><div class="status-pills">
                                    <?php foreach ($statuses as $key => $label): ?>
                                        <label><input type="radio" name="status[<?= (int)$student['id'] ?>]" value="<?= e($key) ?>" <?= $saved['status'] === $key ? 'checked' : '' ?>> <?= e($label) ?></label>
                                    <?php endforeach; ?>
                                </div></td>
                                <td><input name="note[<?= (int)$student['id'] ?>]" value="<?= e(arr_get($saved, 'note', '')) ?>" placeholder="ระบุเหตุผล"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <label class="wide-field room-note-field">หมายเหตุทั้งห้อง
                    <textarea name="room_note" rows="3" placeholder="บันทึกหมายเหตุรวมของห้อง เช่น กิจกรรมพิเศษ เหตุการณ์ประจำวัน หรือข้อมูลที่ต้องแจ้งผู้เกี่ยวข้อง"><?= e($roomNote) ?></textarea>
                </label>
                <p class="actions">
                    <?php if ($isAttendanceSaved): ?>
                        <button type="button" class="saved-button" disabled>บันทึกแล้ว</button>
                        <button class="primary">แก้ไขการเช็คชื่อ</button>
                    <?php else: ?>
                        <button class="primary">บันทึกเช็คชื่อ</button>
                        <button type="button" class="disabled-action" disabled>แก้ไขการเช็คชื่อ</button>
                        <button type="button" class="disabled-action" disabled>ล้างข้อมูลการเช็คชื่อ</button>
                    <?php endif; ?>
                </p>
            </form>
            <?php if ($isAttendanceSaved): ?>
                <form method="post" class="actions" onsubmit="return confirm('ต้องการลบข้อมูลเช็คชื่อรายการนี้หรือไม่?');">
                    <input type="hidden" name="action" value="attendance_delete">
                    <input type="hidden" name="attendance_id" value="<?= (int)$existing['id'] ?>">
                    <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
                    <input type="hidden" name="class_id" value="<?= $classId ?>">
                    <input type="hidden" name="schedule_slot_id" value="<?= $slotId ?>">
                    <input type="hidden" name="subject_id" value="<?= (int)$subjectId ?>">
                    <button class="danger">ล้างข้อมูลการเช็คชื่อ</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php layout_footer(); ?>
