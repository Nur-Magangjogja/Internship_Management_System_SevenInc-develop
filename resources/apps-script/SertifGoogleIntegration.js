/**
 * ============================================================================
 * SERTIF - GOOGLE APPS SCRIPT INTEGRATION GATEWAY (PHASE S9E)
 * ============================================================================
 * 
 * Panduan Deployment untuk Operator Seven Inc:
 * 1. Buka https://script.google.com dengan Akun Google Operasional Seven Inc.
 * 2. Buat "New Project", beri nama: "SertIF Google Integration Gateway".
 * 3. Hapus kode bawaan, lalu salin seluruh isi file ini ke dalam editor (Code.gs).
 * 4. Buka Project Settings (ikon gerigi) -> Script Properties -> Add Script Property:
 *    - SHARED_API_KEY             : [Kunci rahasia panjang acak, sama dengan GOOGLE_APPS_SCRIPT_SHARED_SECRET di .env]
 *    - REGISTRATION_SPREADSHEET_ID: 1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug
 *    - FOLLOWUP_SPREADSHEET_ID    : 1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4
 *    - ATTACHMENTS_ROOT_FOLDER_ID : 1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB
 * 5. Klik "Deploy" -> "New deployment" -> Select type: "Web app":
 *    - Description : SertIF Integration V1
 *    - Execute as  : Me (Akun operasional Seven Inc)
 *    - Who has access: Anyone (Wajib agar webhook Laravel dapat mengirim request)
 * 6. Klik Deploy, berikan otorisasi izin akses Google (Spreadsheets & Drive).
 * 7. Salin "Web app URL" (akhiran /exec) dan tempel ke .env (GOOGLE_APPS_SCRIPT_ENDPOINT_URL)
 *    atau masukkan ke antarmuka admin SertIF di /admin/spreadsheet-connections.
 */

// Canonical 29 Headers for Registration Tab (S9B Contract)
const REGISTRATION_HEADERS_2026 = [
  "Timestamp",
  "Nama Lengkap",
  "NISN / NIM",
  "Program Studi",
  "Email",
  "Jenis Kelamin",
  "No. WhatsApp",
  "Asal Sekolah / Kampus",
  "Kota Tempat Tinggal",
  "Alasan Magang",
  "Jalur",
  "Status",
  "Kesiapan WFO",
  "Dosen / Guru Pembimbing",
  "Kesiapan Shift",
  "Punya Laptop",
  "Peralatan",
  "Mulai Magang",
  "Rencana Durasi",
  "Dapat Info Dari Mana",
  "Instagram",
  "Kos",
  "Minat Bidang",
  "Link Attachment",
  "Ket",
  "SERTIF_SYNC_ID",
  "SERTIF_REGISTRATION_ID",
  "SERTIF_SYNC_STATUS",
  "SERTIF_LAST_SYNC_AT"
];

// Canonical 21 Headers for Follow Up Tab (S9C Contract)
const FOLLOWUP_HEADERS = [
  "No",
  "Nama Sekolah/Kampus",
  "Nama Guru/Dosen",
  "No WA Guru/Dosen",
  "Nama Pemagang",
  "Jurusan Pemagang",
  "HR Bertugas (Attempt 1)",
  "Hasil Follow Up 1",
  "Tanggal Follow Up 1",
  "Catatan 1",
  "HR Bertugas (Attempt 2)",
  "Hasil Follow Up 2",
  "Tanggal Follow Up 2",
  "Catatan 2",
  "HR Bertugas (Attempt 3)",
  "Hasil Follow Up 3",
  "Tanggal Follow Up 3",
  "Catatan 3",
  "Status Hasil Akhir",
  "Status Verifikasi Kontak",
  "SERTIF_SYNC_ID"
];

/**
 * Main Web App POST Entry Point
 */
function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return createJsonResponse({
        success: false,
        error_code: "EMPTY_REQUEST",
        is_retryable: false,
        message: "Body request kosong."
      }, 400);
    }

    var requestBody = JSON.parse(e.postData.contents);
    var authError = validateAuthentication(requestBody);
    if (authError) {
      return createJsonResponse(authError, 401);
    }

    var payload = requestBody.payload || {};
    var action = payload.action;

    switch (action) {
      case "ping":
        return handlePingAction(payload);

      case "upsert_row":
        return handleUpsertRowAction(payload);

      case "upload_attachment":
        return handleUploadAttachmentAction(payload);

      default:
        return createJsonResponse({
          success: false,
          error_code: "UNKNOWN_ACTION",
          is_retryable: false,
          message: "Aksi '" + action + "' tidak didukung."
        }, 400);
    }
  } catch (err) {
    return createJsonResponse({
      success: false,
      error_code: "INTERNAL_ERROR",
      is_retryable: true,
      message: "Terjadi kesalahan internal pada Apps Script: " + err.toString()
    }, 500);
  }
}

/**
 * Handle Ping / Health Check Action
 */
function handlePingAction(payload) {
  var props = PropertiesService.getScriptProperties();
  var regSheetId = props.getProperty("REGISTRATION_SPREADSHEET_ID");
  var fwuSheetId = props.getProperty("FOLLOWUP_SPREADSHEET_ID");
  var driveFolderId = props.getProperty("ATTACHMENTS_ROOT_FOLDER_ID");

  var connectionKey = payload.connection_key || "";
  var requestedSpreadsheetId = payload.spreadsheet_id || "";

  var expectedSpreadsheetId = "";
  if (connectionKey === "registration") {
    expectedSpreadsheetId = regSheetId;
  } else if (connectionKey === "followup") {
    expectedSpreadsheetId = fwuSheetId;
  }

  var idMatched = true;
  if (expectedSpreadsheetId && requestedSpreadsheetId) {
    idMatched = (requestedSpreadsheetId.trim() === expectedSpreadsheetId.trim());
  }

  var regAccessible = false;
  var fwuAccessible = false;
  var driveAccessible = false;
  var targetAccessible = false;

  // Coba buka spreadsheet target yang diminta secara spesifik
  if (requestedSpreadsheetId) {
    try {
      SpreadsheetApp.openById(requestedSpreadsheetId.trim());
      targetAccessible = true;
    } catch (e) {
      targetAccessible = false;
    }
  }

  try {
    if (regSheetId) {
      SpreadsheetApp.openById(regSheetId);
      regAccessible = true;
    }
  } catch (e) {}

  try {
    if (fwuSheetId) {
      SpreadsheetApp.openById(fwuSheetId);
      fwuAccessible = true;
    }
  } catch (e) {}

  try {
    if (driveFolderId) {
      DriveApp.getFolderById(driveFolderId);
      driveAccessible = true;
    }
  } catch (e) {}

  var isSuccess = true;
  var message = "Koneksi Google Apps Script Gateway berhasil diverifikasi.";

  if (requestedSpreadsheetId && !targetAccessible) {
    isSuccess = false;
    message = "Spreadsheet target (" + requestedSpreadsheetId + ") tidak dapat dibuka.";
  } else if (requestedSpreadsheetId && expectedSpreadsheetId && !idMatched) {
    isSuccess = false;
    message = "Spreadsheet ID yang dikirim tidak sesuai dengan konfigurasi target Apps Script Gateway.";
  }

  return createJsonResponse({
    success: isSuccess,
    action: "ping",
    connection_key: connectionKey,
    requested_spreadsheet_id: requestedSpreadsheetId,
    verified_spreadsheet_id: targetAccessible ? requestedSpreadsheetId : null,
    target_accessible: targetAccessible,
    id_matched: idMatched,
    message: message,
    status: {
      registration_spreadsheet: regAccessible,
      followup_spreadsheet: fwuAccessible,
      drive_folder: driveAccessible
    },
    timestamp: new Date().toISOString()
  }, isSuccess ? 200 : 400);
}

/**
 * Handle Atomic Upsert Row Action
 */
function handleUpsertRowAction(payload) {
  var props = PropertiesService.getScriptProperties();
  var connectionKey = payload.connection_key;
  var targetTabName = payload.tab_name;
  var syncId = payload.sync_id;
  var rowData = payload.payload || {};

  var spreadsheetId = payload.spreadsheet_id;
  if (!spreadsheetId) {
    spreadsheetId = (connectionKey === "registration") 
      ? props.getProperty("REGISTRATION_SPREADSHEET_ID") 
      : props.getProperty("FOLLOWUP_SPREADSHEET_ID");
  }

  if (!spreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_ID_MISSING",
      is_retryable: false,
      message: "Spreadsheet ID belum dikonfigurasi."
    }, 400);
  }

  var expectedHeaders = (connectionKey === "registration") ? REGISTRATION_HEADERS_2026 : FOLLOWUP_HEADERS;

  var lock = LockService.getScriptLock();
  var lockAcquired = false;
  try {
    lockAcquired = lock.tryLock(30000); // 30s timeout
  } catch (e) {
    lockAcquired = false;
  }

  if (!lockAcquired) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "LOCK_TIMEOUT",
      is_retryable: true,
      message: "Gagal memperoleh lock spreadsheet dalam 30 detik. Silakan coba lagi."
    }, 503);
  }

  try {
    var ss = SpreadsheetApp.openById(spreadsheetId);
    var sheet = ss.getSheetByName(targetTabName);

    if (!sheet) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "TAB_NOT_FOUND",
        is_retryable: false,
        message: "Tab sheet '" + targetTabName + "' tidak ditemukan pada workbook target."
      }, 404);
    }

    var lastRow = sheet.getLastRow();
    var lastCol = sheet.getLastColumn();

    if (lastCol === 0) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "EMPTY_SHEET",
        is_retryable: false,
        message: "Sheet tidak memiliki baris header."
      }, 400);
    }

    // Read and validate headers on Row 1
    var actualHeaders = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    var syncIdColIndex = -1;

    for (var i = 0; i < actualHeaders.length; i++) {
      if (actualHeaders[i].toString().trim() === "SERTIF_SYNC_ID") {
        syncIdColIndex = i + 1; // 1-based index
        break;
      }
    }

    if (syncIdColIndex === -1) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "MISSING_SYNC_ID_COLUMN",
        is_retryable: false,
        message: "Kolom metadata 'SERTIF_SYNC_ID' tidak ditemukan pada sheet."
      }, 400);
    }

    // Find existing row by SERTIF_SYNC_ID
    var targetRowIndex = -1;
    if (lastRow > 1) {
      var syncIdValues = sheet.getRange(2, syncIdColIndex, lastRow - 1, 1).getValues();
      for (var r = 0; r < syncIdValues.length; r++) {
        if (syncIdValues[r][0].toString().trim() === syncId) {
          targetRowIndex = r + 2; // Accounting for 1-based index and header row
          break;
        }
      }
    }

    // Build row values according to actual sheet header positions
    var rowValues = [];
    for (var h = 0; h < actualHeaders.length; h++) {
      var headerName = actualHeaders[h].toString().trim();
      var cellVal = rowData[headerName];
      if (cellVal === undefined || cellVal === null) {
        cellVal = "-";
      }
      rowValues.push(cellVal);
    }

    var operation = "inserted";
    if (targetRowIndex > 0) {
      // UPDATE existing row
      sheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);
      operation = "updated";
    } else {
      // INSERT new row at end
      targetRowIndex = lastRow + 1;
      sheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);
    }

    // Explicitly flush pending changes before releasing script lock
    SpreadsheetApp.flush();

    return createJsonResponse({
      success: true,
      action: "upsert_row",
      sync_id: syncId,
      row_index: targetRowIndex,
      tab_name: targetTabName,
      operation: operation,
      timestamp: new Date().toISOString()
    });
  } catch (err) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_WRITE_ERROR",
      is_retryable: true,
      message: "Gagal menulis ke Google Sheet: " + err.toString()
    }, 500);
  } finally {
    lock.releaseLock();
  }
}

/**
 * Handle Idempotent Upload Attachment to Google Drive
 */
function handleUploadAttachmentAction(payload) {
  var props = PropertiesService.getScriptProperties();
  var rootFolderId = props.getProperty("ATTACHMENTS_ROOT_FOLDER_ID");

  if (!rootFolderId) {
    return createJsonResponse({
      success: false,
      error_code: "DRIVE_FOLDER_NOT_CONFIGURED",
      is_retryable: false,
      message: "Root folder ID Google Drive belum dikonfigurasi di Script Properties."
    }, 400);
  }

  var syncId = payload.sync_id;
  var year = payload.year || new Date().getFullYear().toString();
  var regId = payload.registration_id || "0";
  var applicantName = payload.applicant_name || "Pemagang";
  var originalFileName = payload.file_name || "attachment.pdf";
  var mimeType = payload.mime_type || "application/octet-stream";
  var base64Data = payload.file_content;

  if (!base64Data) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "EMPTY_FILE_CONTENT",
      is_retryable: false,
      message: "Konten berkas base64 kosong."
    }, 400);
  }

  var lock = LockService.getScriptLock();
  var lockAcquired = false;
  try {
    lockAcquired = lock.tryLock(30000);
  } catch (e) {
    lockAcquired = false;
  }

  if (!lockAcquired) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "LOCK_TIMEOUT",
      is_retryable: true,
      message: "Gagal memperoleh lock Drive dalam 30 detik."
    }, 503);
  }

  try {
    var rootFolder = DriveApp.getFolderById(rootFolderId);
    
    // Subfolder hierarchy: Registrasi / {Year} / {ApplicantName}_{regId}
    var regBaseFolder = getOrCreateSubFolder(rootFolder, "Registrasi");
    var yearFolder = getOrCreateSubFolder(regBaseFolder, year);
    var cleanApplicantName = applicantName.replace(/[^a-zA-Z0-9_\-\s]/g, "").trim().replace(/\s+/g, "_");
    var targetFolderName = cleanApplicantName + "_REG" + regId;
    var targetFolder = getOrCreateSubFolder(yearFolder, targetFolderName);

    // Idempotency check: check if file with this sync identity already exists
    var safeFilePrefix = syncId + "_";
    var existingFiles = targetFolder.getFiles();
    while (existingFiles.hasNext()) {
      var file = existingFiles.next();
      if (file.getName().indexOf(safeFilePrefix) === 0 || file.getDescription() === syncId) {
        // File already exists -> return existing file reference without creating duplicate
        return createJsonResponse({
          success: true,
          action: "upload_attachment",
          sync_id: syncId,
          file_id: file.getId(),
          file_name: file.getName(),
          web_view_link: file.getUrl(),
          operation: "reused_existing",
          timestamp: new Date().toISOString()
        });
      }
    }

    // Decode and save new file
    var decodedBytes = Utilities.base64Decode(base64Data);
    var uniqueFileName = safeFilePrefix + originalFileName;
    var blob = Utilities.newBlob(decodedBytes, mimeType, uniqueFileName);
    
    var createdFile = targetFolder.createFile(blob);
    createdFile.setDescription(syncId);

    // Keep file permissions strictly restricted (inherit from folder)
    return createJsonResponse({
      success: true,
      action: "upload_attachment",
      sync_id: syncId,
      file_id: createdFile.getId(),
      file_name: createdFile.getName(),
      web_view_link: createdFile.getUrl(),
      operation: "created",
      timestamp: new Date().toISOString()
    });
  } catch (err) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "DRIVE_UPLOAD_ERROR",
      is_retryable: true,
      message: "Gagal menyimpan berkas ke Google Drive: " + err.toString()
    }, 500);
  } finally {
    lock.releaseLock();
  }
}

/**
 * Helper: Find or create subfolder by name
 */
function getOrCreateSubFolder(parentFolder, folderName) {
  var subFolders = parentFolder.getFoldersByName(folderName);
  if (subFolders.hasNext()) {
    return subFolders.next();
  }
  return parentFolder.createFolder(folderName);
}

/**
 * Validate HMAC-SHA256 Authentication Signature & Nonce Replay
 */
function validateAuthentication(requestBody) {
  var props = PropertiesService.getScriptProperties();
  var sharedSecret = props.getProperty("SHARED_API_KEY");

  if (!sharedSecret) {
    return {
      success: false,
      error_code: "SERVER_NOT_CONFIGURED",
      is_retryable: false,
      message: "SHARED_API_KEY belum disetel di Script Properties."
    };
  }

  var timestamp = requestBody.timestamp;
  var nonce = requestBody.nonce;
  var signature = requestBody.signature;
  var payload = requestBody.payload;

  if (!timestamp || !nonce || !signature || !payload) {
    return {
      success: false,
      error_code: "MISSING_AUTH_FIELDS",
      is_retryable: false,
      message: "Parameter autentikasi tidak lengkap (timestamp, nonce, signature, payload wajib diisi)."
    };
  }

  // 1. Timestamp freshness check (max 300 seconds drift)
  var currentTimestamp = Math.floor(new Date().getTime() / 1000);
  var diff = Math.abs(currentTimestamp - parseInt(timestamp, 10));
  if (diff > 300) {
    return {
      success: false,
      error_code: "EXPIRED_REQUEST",
      is_retryable: false,
      message: "Request kadaluarsa (waktu request melebihi toleransi 300 detik)."
    };
  }

  // 2. Replay attack protection via CacheService
  var cache = CacheService.getScriptCache();
  var cacheKey = "nonce_" + nonce;
  if (cache.get(cacheKey) !== null) {
    return {
      success: false,
      error_code: "REPLAYED_NONCE",
      is_retryable: false,
      message: "Nonce telah digunakan sebelumnya (Replay Attack dicegah)."
    };
  }
  cache.put(cacheKey, "1", 360); // Store nonce for 6 minutes

  // 3. HMAC-SHA256 Signature calculation
  var canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
  var expectedSignatureBytes = Utilities.computeHmacSha256Signature(canonicalData, sharedSecret);
  var expectedSignatureHex = bytesToHex(expectedSignatureBytes);

  if (signature.toLowerCase() !== expectedSignatureHex.toLowerCase()) {
    return {
      success: false,
      error_code: "INVALID_SIGNATURE",
      is_retryable: false,
      message: "Tanda tangan HMAC-SHA256 tidak valid."
    };
  }

  return null; // Valid
}

/**
 * Convert byte array to hexadecimal string
 */
function bytesToHex(bytes) {
  var hex = "";
  for (var i = 0; i < bytes.length; i++) {
    var byteVal = bytes[i];
    if (byteVal < 0) byteVal += 256;
    var byteHex = byteVal.toString(16);
    if (byteHex.length === 1) byteHex = "0" + byteHex;
    hex += byteHex;
  }
  return hex;
}

/**
 * Return ContentService JSON Output
 */
function createJsonResponse(obj, statusCode) {
  var output = ContentService.createTextOutput(JSON.stringify(obj));
  output.setMimeType(ContentService.MimeType.JSON);
  return output;
}
