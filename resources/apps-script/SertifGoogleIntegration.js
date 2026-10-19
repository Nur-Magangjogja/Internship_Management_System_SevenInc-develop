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
 *    - REGISTRATION_SPREADSHEET_ID: 1lY3UhhViRCOstvZZeJtEEW2Yol8eFIIFgkCqt3haQSU
 *    - FOLLOWUP_SPREADSHEET_ID    : 1aEuwUFQED_DODDcf9IPm2gKe4cbdpPYim77mYu9yDEU
 *    - ATTACHMENTS_ROOT_FOLDER_ID : 1QRk_vowPYJfx2SmNkbg8-W2yy5o0nPRB
 * 5. Klik "Deploy" -> "New deployment" -> Select type: "Web app":
 *    - Description : SertIF Integration V1
 *    - Execute as  : Me (Akun operasional Seven Inc)
 *    - Who has access: Anyone (Wajib agar webhook Laravel dapat mengirim request)
 * 6. Klik Deploy, berikan otorisasi izin akses Google (Spreadsheets & Drive).
 * 7. Salin "Web app URL" (akhiran /exec) dan tempel ke .env (GOOGLE_APPS_SCRIPT_ENDPOINT_URL)
 *    atau masukkan ke antarmuka admin SertIF di /admin/spreadsheet-connections.
 */

// Canonical 40-Column Contract Definition for Registration Tab 2026 (Order 1 to 40)
var REG_CONTRACT_2026 = [
  { order: 1, dbKey: "Timestamp", test: function(h) { return /timestamp|waktu/i.test(h); } },
  { order: 2, dbKey: "Nama Lengkap", aliases: ["Nama"], test: function(h) { return (/nama\s+lengkap/i.test(h) || /^nama$/i.test(h)) && !/pembimbing/i.test(h); } },
  { order: 3, dbKey: "NIM (Nomor Induk Mahasiswa)", aliases: ["NIM", "NISN"], test: function(h) { return /nim|nisn/i.test(h); } },
  { order: 4, dbKey: "Program Studi", aliases: ["Jurusan", "Prodi"], test: function(h) { return /program\s+studi|jurusan|prodi/i.test(h); } },
  { order: 5, dbKey: "Email", test: function(h) { return /email/i.test(h); } },
  { order: 6, dbKey: "Jenis Kelamin", aliases: ["Gender"], test: function(h) { return /jenis\s+kelamin|gender/i.test(h); } },
  { order: 7, dbKey: "No. HP Aktif yang bisa dihubungi dan chat WA", aliases: ["No. HP", "Nomor WhatsApp", "No WA", "No. HP Aktif"], test: function(h) { return (/no.*hp|whats?app/i.test(h) && !/dosen|guru|pembimbing/i.test(h)); } },
  { order: 8, dbKey: "Asal Sekolah/Kampus", aliases: ["Sekolah/Kampus", "Asal Kampus", "Institusi"], test: function(h) { return /sekolah.*kampus|asal.*sekolah|institusi/i.test(h); } },
  { order: 9, dbKey: "Nama Kota/Daerah tempat tinggal Anda saat ini", aliases: ["Kota Tinggal", "Domisili", "Kota Asal"], test: function(h) { return /kota.*tinggal|kota.*daerah|kota\s+asal|domisili/i.test(h); } },
  { order: 10, dbKey: "Mengapa Anda ingin Magang/PKL disini?", aliases: ["Alasan Magang", "Alasan PKL", "Mengapa Anda ingin Magang/PKL disini"], test: function(h) { return /mengapa.*ingin|alasan.*magang|alasan.*pkl/i.test(h); } },
  { order: 11, dbKey: "Jenis Magang apa yang Anda pilih?", aliases: ["Jenis Magang", "Jalur Magang", "Jenis Magang apa yang Anda pilih"], test: function(h) { return /jenis\s+magang|jalur.*magang/i.test(h); } },
  { order: 12, dbKey: "Status Anda saat ini", aliases: ["Status Saat Ini", "Status"], test: function(h) { return /status.*saat\s+ini|^status$/i.test(h); } },
  { order: 13, dbKey: "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?", aliases: ["Kesiapan WFO", "Siap WFO", "Apakah Anda siap dengan Sistem Magang WFO"], test: function(h) { return /wfo|work\s+from\s+office/i.test(h); } },
  { order: 14, dbKey: "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan", aliases: ["No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan (bisa diisi menyusul, jika belum dapat dosen pembimbing).", "Kontak Dosen/Guru Pembimbing", "Pembimbing PKL/Magang"], test: function(h) { return (/dosen|guru\s+pembimbing/i.test(h) || (/pembimbing/i.test(h) && /jabatan/i.test(h))) && !/^nama\s+pembimbing/i.test(h) && !/(no|wa).*pembimbing$/i.test(h); } },
  { order: 15, dbKey: "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)", aliases: ["Hari magang adalah Senin - Sabtu. Jam magangnya dijadwalkan shifting bergantian antara : Kantor 1 : Shift pagi (06.30 - 13.00 WIB) / Shift siang (13.00 - 21.00 WIB). Kantor 2 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) / Shift siang (13.00 - 21.00 WIB). Kantor 4 :  Shift pagi (06.30 - 13.00 WIB) / Shift Middle (09.00 - 17.00 WIB) /  Shift siang (13.00 - 21.00 WIB). Apakah Anda sanggup?", "Kesanggupan Jadwal", "Kesiapan Shifting", "Kesanggupan jadwal Senin–Sabtu dan shifting"], test: function(h) { return /senin.*sabtu|shifting|kesiapan\s+shift|apakah\s+anda\s+sanggup/i.test(h); } },
  { order: 16, dbKey: "Apakah Anda ada alat kerja sendiri (LAPTOP) yang bisa dipakai selama Magang/PKL?", legacyKey: "Apakah ada laptop sendiri", aliases: ["Punya Laptop Sendiri", "Apakah ada laptop sendiri", "Apakah Anda ada alat kerja sendiri (LAPTOP)"], test: function(h) { return /laptop/i.test(h) && !/peralatan/i.test(h) && !/alat.*(miliki|milik|bawa|dimiliki)/i.test(h); } },
  { order: 17, dbKey: "Jika YA, alat apa yang Anda miliki, yang bisa Anda bawa selama Magang/PKL?", legacyKey: "Jika YA, alat apa yang dimiliki/dibawa", aliases: ["Alat yang dibawa", "Jika YA, alat apa yang dimiliki/dibawa", "Jika YA, alat apa yang Anda miliki"], test: function(h) { return /alat.*(miliki|milik|bawa|dimiliki)/i.test(h) && !/peralatan/i.test(h); } },
  { order: 18, dbKey: "Kapan Anda berencana mulai Magang/PKL? (tulis lengkap tanggal, bulan, tahun)", legacyKey: "Kapan berencana mulai Magang/PKL", aliases: ["Rencana Mulai Magang", "Tanggal Mulai", "Kapan berencana mulai Magang/PKL", "Kapan Anda berencana mulai Magang/PKL"], test: function(h) { return /kapan.*mulai|rencana.*mulai|mulai.*magang/i.test(h); } },
  { order: 19, dbKey: "Berapa lama Anda akan Magang/PKL?", legacyKey: "Berapa lama akan Magang/PKL", aliases: ["Durasi Magang", "Lama Magang", "Berapa lama akan Magang/PKL", "Berapa lama Anda akan Magang/PKL"], test: function(h) { return /\blama\b.*magang|\bdurasi\b/i.test(h); } },
  { order: 20, dbKey: "Anda tahu info magang ini dari mana?", legacyKey: "Sumber info magang", aliases: ["Sumber Informasi", "Sumber info magang", "Anda tahu info magang ini dari mana"], test: function(h) { return /tahu.*info|sumber.*info|dapat.*info/i.test(h); } },
  { order: 21, dbKey: "Sosial Media (Instagram) yang dimiliki", legacyKey: "Instagram", aliases: ["Akun Instagram", "Instagram", "Sosial Media (Instagram)"], test: function(h) { return /instagram|sosial\s+media/i.test(h); } },
  { order: 22, dbKey: "Apakah anda membutuhkan info kost/kontrakan deket kantor?", legacyKey: "Butuh info kost/kontrakan", aliases: ["Info Kost", "Butuh Kost", "Butuh info kost/kontrakan", "Apakah anda membutuhkan info kost/kontrakan"], test: function(h) { return /kost|kontrakan|^kos$/i.test(h); } },
  { order: 23, dbKey: "Program Magang/PKL apa yang Anda minati?", legacyKey: "Program Magang/PKL yang diminati", aliases: ["Bidang Minat", "Divisi Magang", "Program Magang/PKL yang diminati", "Program Magang/PKL apa yang Anda minati"], test: function(h) { return /minat|bidang.*magang/i.test(h); } },
  { order: 24, dbKey: "Tempat Lahir", aliases: ["Tempat Kelahiran", "Birth Place"], test: function(h) { return /tempat\s+lahir/i.test(h); } },
  { order: 25, dbKey: "Tanggal Lahir", aliases: ["Tgl Lahir", "Birth Date"], test: function(h) { return /tanggal\s+lahir|tgl\s+lahir|birth\s*date/i.test(h); } },
  { order: 26, dbKey: "Agama", aliases: ["Religion"], test: function(h) { return /^agama$|^religion$/i.test(h); } },
  { order: 27, dbKey: "Software Desain", aliases: ["Design Software", "Software Desain yang Dikuasai"], test: function(h) { return /software\s+desain/i.test(h); } },
  { order: 28, dbKey: "Software Video", aliases: ["Video Editing Software", "Software Video yang Dikuasai"], test: function(h) { return /software\s+video/i.test(h); } },
  { order: 29, dbKey: "Bahasa Pemrograman", aliases: ["Programming Languages", "Bahasa Koding"], test: function(h) { return /bahasa\s+pemrograman|programming/i.test(h); } },
  { order: 30, dbKey: "Digital Marketing", aliases: ["Keahlian Digital Marketing"], test: function(h) { return /digital\s+marketing/i.test(h); } },
  { order: 31, dbKey: "Peralatan (Laptop, Kamera, Tripod, dll)", aliases: ["Peralatan", "Alat Milik Sendiri", "Peralatan (Laptop, Kamera, Tripod dll)"], test: function(h) { return /^peralatan/i.test(h) || /peralatan.*laptop/i.test(h); } },
  { order: 32, dbKey: "Nama Pembimbing", aliases: ["Nama Guru Pembimbing", "Nama Dosen Pembimbing"], test: function(h) { return /^nama\s+pembimbing/i.test(h); } },
  { order: 33, dbKey: "No. WA Pembimbing", aliases: ["Nomor WA Pembimbing", "No HP Pembimbing"], test: function(h) { return /(no|wa).*pembimbing/i.test(h) && !/dosen|guru|jabatan/i.test(h); } },
  { order: 34, dbKey: "Kegiatan Lain Selain Magang", aliases: ["Aktivitas Lain", "Kegiatan Lain"], test: function(h) { return /kegiatan\s+lain/i.test(h); } },
  { 
    order: 35, 
    dbKey: "Silakan Upload (berupa file Pdf) : CV, Scan KTM/KTP dan Portofolio max. 10 MB", 
    legacyKey: "Upload PDF CV + scan KTM/KTP + portofolio max 10MB",
    aliases: [
      "Silakan Upload (berupa file Pdf) : CV, Scan KTM/KTP dan Portofolio max. 10 MB",
      "Upload PDF CV + scan KTM/KTP + portofolio max 10MB",
      "attachment_url",
      "cv_ktp_portofolio"
    ],
    test: function(h) { return /silakan\s+upload|upload.*pdf|attachment|cv.*ktm.*portofolio/i.test(h); } 
  },
  { order: 36, dbKey: "KET", aliases: ["Keterangan", "Status Approval"], test: function(h) { return /^ket$|^keterangan$/i.test(h); } },
  { order: 37, dbKey: "SERTIF_SYNC_ID", test: function(h) { return /^sertif_sync_id$/i.test(h); } },
  { order: 38, dbKey: "SERTIF_REGISTRATION_ID", test: function(h) { return /^sertif_registration_id$/i.test(h); } },
  { order: 39, dbKey: "SERTIF_SYNC_STATUS", test: function(h) { return /^sertif_sync_status$/i.test(h); } },
  { order: 40, dbKey: "SERTIF_LAST_SYNC_AT", test: function(h) { return /^sertif_last_sync_at$/i.test(h); } }
];

// Canonical 21-Column Contract Definition for Follow Up Tab (Order 1 to 21)
var FOLLOWUP_CONTRACT = [
  { order: 1, dbKey: "No.", test: function(h) { return /^no\.?$|^nomor$/i.test(h); } },
  { order: 2, dbKey: "Tahun Follow Up", test: function(h) { return /tahun/i.test(h); } },
  { order: 3, dbKey: "Sekolah/Kampus", test: function(h) { return /sekolah|kampus|institusi/i.test(h); } },
  { order: 4, dbKey: "Dosen/Guru", test: function(h) { return (/dosen|guru/i.test(h) && !/status/i.test(h)); } },
  { order: 5, dbKey: "Nomor Telepon", test: function(h) { return /telepon|phone|no.*wa/i.test(h); } },
  { order: 6, dbKey: "Status Guru Dosen", test: function(h) { return /status.*guru|status.*dosen|status.*kontak/i.test(h); } },
  { order: 7, dbKey: "Follow Up 1", test: function(h) { return (/follow\s*up\s*1|hasil.*1/i.test(h) && !/nama|petugas|hr|mengajukan/i.test(h)); } },
  { order: 8, dbKey: "Follow Up 2", test: function(h) { return (/follow\s*up\s*2|hasil.*2/i.test(h) && !/nama|petugas|hr|mengajukan/i.test(h)); } },
  { order: 9, dbKey: "Follow Up 3", test: function(h) { return (/follow\s*up\s*3|hasil.*3/i.test(h) && !/nama|petugas|hr|mengajukan/i.test(h)); } },
  { order: 10, dbKey: "Kapan PKL/Magang Nya", test: function(h) { return /kapan|periode|jadwal/i.test(h); } },
  { order: 11, dbKey: "Respon", test: function(h) { return /respon|status.*hasil|hasil.*akhir/i.test(h); } },
  { order: 12, dbKey: "Nama yang mengajukan Kerjasama/ Follow Up 1", test: function(h) { return /nama.*(kerjasama|follow\s*up).*1|hr.*1|petugas.*1/i.test(h); } },
  { order: 13, dbKey: "Nama yang mengajukan Kerjasama/ Follow Up 2", test: function(h) { return /nama.*(kerjasama|follow\s*up).*2|hr.*2|petugas.*2/i.test(h); } },
  { order: 14, dbKey: "Nama yang mengajukan Kerjasama/ Follow Up 3", test: function(h) { return /nama.*(kerjasama|follow\s*up).*3|hr.*3|petugas.*3/i.test(h); } },
  { order: 15, dbKey: "KET", test: function(h) { return /^ket$|^catatan/i.test(h); } },
  { order: 16, dbKey: "SERTIF_SYNC_ID", test: function(h) { return /^sertif_sync_id$/i.test(h); } },
  { order: 17, dbKey: "SERTIF_INSTITUTION_ID", test: function(h) { return /^sertif_institution_id$/i.test(h); } },
  { order: 18, dbKey: "SERTIF_CONTACT_ID", test: function(h) { return /^sertif_contact_id$/i.test(h); } },
  { order: 19, dbKey: "SERTIF_FOLLOWUP_CYCLE_ID", test: function(h) { return /^sertif_followup_cycle_id$/i.test(h); } },
  { order: 20, dbKey: "SERTIF_SYNC_STATUS", test: function(h) { return /^sertif_sync_status$/i.test(h); } },
  { order: 21, dbKey: "SERTIF_LAST_SYNC_AT", test: function(h) { return /^sertif_last_sync_at$/i.test(h); } }
];

/**
 * Match a raw spreadsheet header string to a canonical registration slot (1-40).
 * Uses a robust two-pass strategy:
 * Pass 1: Strict exact matching on dbKey, legacyKey, and known aliases across all slots.
 * Pass 2: Fallback regex pattern matching if no exact header match was found.
 */
function matchRegistrationSlot(headerRaw) {
  if (headerRaw === undefined || headerRaw === null) return null;
  var clean = headerRaw.toString().replace(/[\r\n]+/g, " ").trim();
  if (clean === "") return null;

  var lowerClean = clean.toLowerCase();

  // Pass 1: Strict exact matches against canonical dbKey, legacyKey, or aliases
  for (var i = 0; i < REG_CONTRACT_2026.length; i++) {
    var slot = REG_CONTRACT_2026[i];
    if (slot.dbKey === clean || slot.dbKey.toLowerCase() === lowerClean) {
      return slot;
    }
    if (slot.legacyKey && (slot.legacyKey === clean || slot.legacyKey.toLowerCase() === lowerClean)) {
      return slot;
    }
    if (slot.aliases && Array.isArray(slot.aliases)) {
      for (var a = 0; a < slot.aliases.length; a++) {
        if (slot.aliases[a] === clean || slot.aliases[a].toLowerCase() === lowerClean) {
          return slot;
        }
      }
    }
  }

  // Pass 2: Fallback pattern matching (only reached if exact match failed)
  for (var j = 0; j < REG_CONTRACT_2026.length; j++) {
    var slotFallback = REG_CONTRACT_2026[j];
    if (slotFallback.test && slotFallback.test(clean)) {
      return slotFallback;
    }
  }

  return null;
}

/**
 * Match a raw spreadsheet header string to a canonical follow-up slot (1-21).
 * Uses a robust two-pass strategy:
 * Pass 1: Strict exact matching on dbKey and aliases across all slots.
 * Pass 2: Fallback regex pattern matching if no exact header match was found.
 */
function matchFollowUpSlot(headerRaw) {
  if (headerRaw === undefined || headerRaw === null) return null;
  var clean = headerRaw.toString().replace(/[\r\n]+/g, " ").trim();
  if (clean === "") return null;

  var lowerClean = clean.toLowerCase();

  // Pass 1: Exact matches against canonical dbKey or aliases
  for (var i = 0; i < FOLLOWUP_CONTRACT.length; i++) {
    var slot = FOLLOWUP_CONTRACT[i];
    if (slot.dbKey === clean || slot.dbKey.toLowerCase() === lowerClean) {
      return slot;
    }
    if (slot.aliases && Array.isArray(slot.aliases)) {
      for (var a = 0; a < slot.aliases.length; a++) {
        if (slot.aliases[a] === clean || slot.aliases[a].toLowerCase() === lowerClean) {
          return slot;
        }
      }
    }
  }

  // Pass 2: Fallback pattern matching
  for (var j = 0; j < FOLLOWUP_CONTRACT.length; j++) {
    var slotFallback = FOLLOWUP_CONTRACT[j];
    if (slotFallback.test && slotFallback.test(clean)) {
      return slotFallback;
    }
  }

  return null;
}

/**
 * Ensure target sheet tab exists and automatically synchronize Row 12 headers
 * (plus realign existing data rows if columns were added, renamed, or reordered).
 */
function ensureSheetAndSyncHeaders(ss, tabName, schemaHeaders, headerRowIndex) {
  var sheet = ss.getSheetByName(tabName);
  if (!sheet && typeof ss.insertSheet === "function") {
    sheet = ss.insertSheet(tabName);
  }
  if (!sheet || !Array.isArray(schemaHeaders) || schemaHeaders.length === 0) {
    return sheet;
  }

  var lastRow = sheet.getLastRow();
  var lastCol = sheet.getLastColumn();

  if (lastCol === 0 || lastRow < headerRowIndex) {
    sheet.getRange(headerRowIndex, 1, 1, schemaHeaders.length).setValues([schemaHeaders]);
    return sheet;
  }

  var oldHeaders = sheet.getRange(headerRowIndex, 1, 1, lastCol).getValues()[0];
  var headersIdentical = (oldHeaders.length === schemaHeaders.length);
  if (headersIdentical) {
    for (var i = 0; i < schemaHeaders.length; i++) {
      var oldClean = (oldHeaders[i] || "").toString().replace(/[\r\n]+/g, " ").trim();
      var newClean = (schemaHeaders[i] || "").toString().replace(/[\r\n]+/g, " ").trim();
      if (oldClean !== newClean) {
        headersIdentical = false;
        break;
      }
    }
  }

  if (headersIdentical) {
    return sheet;
  }

  // Build mapping from each new header index -> old column index (0-based)
  var colMap = [];
  var usedOldIndices = {};
  for (var n = 0; n < schemaHeaders.length; n++) {
    var targetHeader = (schemaHeaders[n] || "").toString().replace(/[\r\n]+/g, " ").trim();
    var targetLower = targetHeader.toLowerCase();
    var foundOldIdx = -1;

    for (var o = 0; o < oldHeaders.length; o++) {
      if (usedOldIndices[o]) continue;
      var oldH = (oldHeaders[o] || "").toString().replace(/[\r\n]+/g, " ").trim();
      if (oldH.toLowerCase() === targetLower && oldH !== "") {
        foundOldIdx = o;
        usedOldIndices[o] = true;
        break;
      }
    }

    if (foundOldIdx === -1) {
      var targetSlot = matchRegistrationSlot(targetHeader);
      if (targetSlot) {
        for (var o2 = 0; o2 < oldHeaders.length; o2++) {
          if (usedOldIndices[o2]) continue;
          var oldSlot = matchRegistrationSlot(oldHeaders[o2]);
          if (oldSlot && oldSlot.order === targetSlot.order) {
            foundOldIdx = o2;
            usedOldIndices[o2] = true;
            break;
          }
        }
      }
    }

    colMap.push(foundOldIdx);
  }

  // Realign existing data rows (Row 13+) so KET and SERTIF_SYNC_ID never shift into wrong columns
  var numDataRows = lastRow - headerRowIndex;
  if (numDataRows > 0) {
    var oldData = sheet.getRange(headerRowIndex + 1, 1, numDataRows, lastCol).getValues();
    var newData = [];
    for (var r = 0; r < oldData.length; r++) {
      var newRow = [];
      for (var c = 0; c < schemaHeaders.length; c++) {
        var mappedOldIdx = colMap[c];
        if (mappedOldIdx >= 0 && mappedOldIdx < oldData[r].length) {
          var val = oldData[r][mappedOldIdx];
          newRow.push(val === "" || val === null || val === undefined ? "-" : val);
        } else {
          newRow.push("-");
        }
      }
      newData.push(newRow);
    }
    sheet.getRange(headerRowIndex + 1, 1, numDataRows, schemaHeaders.length).setValues(newData);
  }

  // Write updated headers on headerRowIndex
  sheet.getRange(headerRowIndex, 1, 1, schemaHeaders.length).setValues([schemaHeaders]);

  // If new headers require columns beyond current max columns, ensure sheet has enough columns
  var currentMaxCols = sheet.getMaxColumns();
  if (schemaHeaders.length > currentMaxCols) {
    sheet.insertColumnsAfter(currentMaxCols, schemaHeaders.length - currentMaxCols);
  }

  // Unhide any columns within the active schema range in case they were previously hidden
  try {
    if (typeof sheet.unhideColumn === "function") {
      sheet.unhideColumn(sheet.getRange(headerRowIndex, 1, 1, schemaHeaders.length));
    }
  } catch (uhErr) {}

  // Clear and delete/hide leftover columns to the right if column count shrank (e.g. fields disabled)
  if (lastCol > schemaHeaders.length) {
    var extraCols = lastCol - schemaHeaders.length;
    var clearRows = Math.max(1, lastRow - headerRowIndex + 1);
    var extraRange = sheet.getRange(headerRowIndex, schemaHeaders.length + 1, clearRows, extraCols);
    try {
      if (typeof extraRange.clear === "function") {
        extraRange.clear({ contentsOnly: false, formatOnly: false });
      } else if (typeof extraRange.clearContent === "function") {
        extraRange.clearContent();
      }
    } catch (clrErr) {
      try { extraRange.clearContent(); } catch (clrErr2) {}
    }

    // Attempt to permanently delete excess columns or hide them so they disappear from view
    try {
      if (typeof sheet.deleteColumns === "function" && sheet.getMaxColumns() > schemaHeaders.length) {
        var colsToDelete = Math.min(extraCols, sheet.getMaxColumns() - schemaHeaders.length);
        if (colsToDelete > 0) {
          sheet.deleteColumns(schemaHeaders.length + 1, colsToDelete);
        }
      }
    } catch (delErr) {
      try {
        if (typeof sheet.hideColumns === "function") {
          sheet.hideColumns(schemaHeaders.length + 1, extraCols);
        }
      } catch (hideErr) {}
    }
  }

  return sheet;
}

/**
 * Build mapped row values for sheet columns, strictly validating contract compatibility.
 */
function buildRowValuesForSheet(actualHeaders, rowData, connectionKey, allowDynamicRegistrationCols) {
  var isRegistration = (connectionKey === "registration");
  var expectedCols = isRegistration ? 40 : 21;

  if (isRegistration && allowDynamicRegistrationCols === true) {
    if (!actualHeaders || actualHeaders.length < 40) {
      return {
        error: "Jumlah kolom spreadsheet pendaftaran minimal 40 kolom: ditemukan " + (actualHeaders ? actualHeaders.length : 0) + " kolom.",
        error_code: "SCHEMA_MISMATCH"
      };
    }

    var dynValues = [];
    for (var dh = 0; dh < actualHeaders.length; dh++) {
      var dRawHeader = actualHeaders[dh];
      if (dRawHeader === undefined || dRawHeader === null || dRawHeader.toString().trim() === "") {
        return {
          error: "Header spreadsheet pada kolom " + (dh + 1) + " kosong.",
          error_code: "SCHEMA_MISMATCH"
        };
      }

      var dRawStr = dRawHeader.toString();
      var dCleanStr = dRawStr.replace(/[\r\n]+/g, " ").trim();
      var dSlot = matchRegistrationSlot(dRawHeader);
      var dVal = undefined;

      if (rowData.hasOwnProperty(dRawStr)) {
        dVal = rowData[dRawStr];
      } else if (rowData.hasOwnProperty(dRawStr.trim())) {
        dVal = rowData[dRawStr.trim()];
      } else if (rowData.hasOwnProperty(dCleanStr)) {
        dVal = rowData[dCleanStr];
      } else if (dSlot && rowData.hasOwnProperty(dSlot.dbKey)) {
        dVal = rowData[dSlot.dbKey];
      } else if (dSlot && dSlot.legacyKey && rowData.hasOwnProperty(dSlot.legacyKey)) {
        dVal = rowData[dSlot.legacyKey];
      }

      if (dVal === undefined) {
        for (var dk in rowData) {
          if (rowData.hasOwnProperty(dk)) {
            var cleanDk = dk.toString().replace(/[\r\n]+/g, " ").trim();
            if (cleanDk.toLowerCase() === dCleanStr.toLowerCase() || (dSlot && (cleanDk === dSlot.dbKey || (dSlot.test && dSlot.test(cleanDk))))) {
              dVal = rowData[dk];
              break;
            }
          }
        }
      }

      if (dVal === undefined || dVal === null || dVal === "") {
        dVal = "-";
      }
      dynValues.push(dVal);
    }

    return { values: dynValues };
  }

  if (!actualHeaders || actualHeaders.length !== expectedCols) {
    return {
      error: "Jumlah kolom spreadsheet tidak sesuai kontrak " + (isRegistration ? "Pendaftaran 2026 (harus tepat 40 kolom)" : "Follow-Up (harus tepat 21 kolom)") + ": ditemukan " + (actualHeaders ? actualHeaders.length : 0) + " kolom.",
      error_code: "SCHEMA_MISMATCH"
    };
  }

  var rowValues = [];

  for (var h = 0; h < actualHeaders.length; h++) {
    var rawHeader = actualHeaders[h];
    if (rawHeader === undefined || rawHeader === null || rawHeader.toString().trim() === "") {
      return {
        error: "Header spreadsheet pada kolom " + (h + 1) + " kosong.",
        error_code: "SCHEMA_MISMATCH"
      };
    }

    var slot = isRegistration ? matchRegistrationSlot(rawHeader) : matchFollowUpSlot(rawHeader);
    if (!slot) {
      return {
        error: "Header spreadsheet pada kolom " + (h + 1) + " ('" + rawHeader.toString().trim() + "') tidak sesuai dengan kontrak " + (isRegistration ? "Pendaftaran 2026" : "Follow-Up") + ".",
        error_code: "SCHEMA_MISMATCH"
      };
    }

    // Strict slot order guardrail: prevent column swapping/scrambling
    if (slot.order !== (h + 1)) {
      return {
        error: "Pertukaran posisi kolom terdeteksi: header '" + rawHeader.toString().trim() + "' seharusnya berada di kolom " + slot.order + ", tetapi ditemukan di kolom " + (h + 1) + ".",
        error_code: "SCHEMA_MISMATCH"
      };
    }

    var cellVal = undefined;
    var rawStr = rawHeader.toString();
    var cleanStr = rawStr.replace(/[\r\n]+/g, " ").trim();

    if (rowData.hasOwnProperty(rawStr)) {
      cellVal = rowData[rawStr];
    } else if (rowData.hasOwnProperty(rawStr.trim())) {
      cellVal = rowData[rawStr.trim()];
    } else if (rowData.hasOwnProperty(slot.dbKey)) {
      cellVal = rowData[slot.dbKey];
    } else if (slot.legacyKey && rowData.hasOwnProperty(slot.legacyKey)) {
      cellVal = rowData[slot.legacyKey];
    } else if (rowData.hasOwnProperty(cleanStr)) {
      cellVal = rowData[cleanStr];
    } else if (slot.aliases) {
      for (var a = 0; a < slot.aliases.length; a++) {
        if (rowData.hasOwnProperty(slot.aliases[a])) {
          cellVal = rowData[slot.aliases[a]];
          break;
        }
      }
    }

    if (cellVal === undefined) {
      for (var k in rowData) {
        if (rowData.hasOwnProperty(k)) {
          var cleanK = k.toString().replace(/[\r\n]+/g, " ").trim();
          if (cleanK === cleanStr || cleanK === slot.dbKey || cleanK === slot.legacyKey || (slot.test && slot.test(cleanK))) {
            cellVal = rowData[k];
            break;
          }
        }
      }
    }

    if (cellVal === undefined) {
      return {
        error: "Field data untuk kolom '" + cleanStr + "' (slot: " + slot.dbKey + ") tidak ditemukan dalam payload yang dikirim.",
        error_code: "SCHEMA_MISMATCH"
      };
    }

    if (cellVal === null || cellVal === "") {
      cellVal = "-";
    }

    rowValues.push(cellVal);
  }

  return { values: rowValues };
}

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

      case "update_row":
        return handleUpdateRowAction(payload);

      case "sync_sheet_schema":
        return handleSyncSheetSchemaAction(payload);

      case "lookup_row":
        return handleLookupRowAction(payload);

      case "upload_attachment":
        return handleUploadAttachmentAction(payload);

      case "ensure_extension_columns":
        return handleEnsureExtensionColumnsAction(payload);

      case "upsert_extension_row":
        return handleUpsertExtensionRowAction(payload);

      case "inspect_sidecar_alignment":
        return handleInspectSidecarAlignmentAction(payload);

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
  var connectionKey = payload.connection_key;
  var targetTabName = payload.tab_name;
  var syncId = payload.sync_id;
  var rowData = payload.payload || {};

  // 1. Strict validation of sync_id
  if (syncId === undefined || syncId === null || typeof syncId !== "string" || syncId.trim() === "") {
    return createJsonResponse({
      success: false,
      sync_id: syncId || null,
      error_code: "INVALID_SYNC_ID",
      is_retryable: false,
      message: "sync_id wajib berupa string yang tidak kosong."
    }, 400);
  }

  // 2. Strict validation of SERTIF_SYNC_ID inside payload
  var payloadSyncId = undefined;
  if (rowData && typeof rowData === "object") {
    if (rowData.hasOwnProperty("SERTIF_SYNC_ID")) {
      payloadSyncId = rowData["SERTIF_SYNC_ID"];
    } else {
      for (var k in rowData) {
        if (rowData.hasOwnProperty(k) && k.toString().trim().toUpperCase() === "SERTIF_SYNC_ID") {
          payloadSyncId = rowData[k];
          break;
        }
      }
    }
  }

  if (payloadSyncId === undefined || payloadSyncId === null || payloadSyncId.toString().trim() === "") {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "INVALID_SYNC_ID",
      is_retryable: false,
      message: "Field SERTIF_SYNC_ID wajib tersedia dan tidak boleh kosong di dalam payload."
    }, 400);
  }

  if (syncId.toString().trim() !== payloadSyncId.toString().trim()) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SYNC_ID_MISMATCH",
      is_retryable: false,
      message: "sync_id ('" + syncId + "') tidak sesuai dengan nilai SERTIF_SYNC_ID dalam payload ('" + payloadSyncId + "')."
    }, 400);
  }

  // 3. Strict allowlist validation for connection_key
  if (connectionKey !== "registration" && connectionKey !== "followup") {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "connection_key '" + connectionKey + "' tidak valid. Hanya diizinkan 'registration' atau 'followup'."
    }, 400);
  }

  // 4. Strict allowlist validation for tab_name (2020-2099 for registration, explicit string for followup)
  var validTab = false;
  if (connectionKey === "registration") {
    if (typeof targetTabName === "string" && /^\d{4}$/.test(targetTabName.trim())) {
      var yearNum = parseInt(targetTabName.trim(), 10);
      if (yearNum >= 2020 && yearNum <= 2099) {
        validTab = true;
      }
    }
  } else if (connectionKey === "followup" && targetTabName === "Follow Up Guru Dosen") {
    validTab = true;
  }

  if (!validTab) {
    var expectedTab = (connectionKey === "registration") ? "2020-2099 (4 digit)" : "Follow Up Guru Dosen";
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "Tab '" + (targetTabName || "") + "' tidak diizinkan untuk koneksi '" + connectionKey + "'. Tab resmi yang diizinkan hanya '" + expectedTab + "'."
    }, 400);
  }

  // 5. Strict fail-closed Spreadsheet ID resolution from Script Properties
  var props = null;
  try {
    if (typeof PropertiesService !== "undefined" && PropertiesService && PropertiesService.getScriptProperties) {
      props = PropertiesService.getScriptProperties();
    }
  } catch (e) {
    props = null;
  }
  if (!props && typeof global !== "undefined" && global._mockScriptProperties) {
    props = global._mockScriptProperties;
  }

  var regSheetId = "";
  var fwuSheetId = "";
  if (props && typeof props.getProperty === "function") {
    regSheetId = (props.getProperty("REGISTRATION_SPREADSHEET_ID") || "").toString().trim();
    fwuSheetId = (props.getProperty("FOLLOWUP_SPREADSHEET_ID") || "").toString().trim();
  }

  var expectedSpreadsheetId = (connectionKey === "registration") ? regSheetId : fwuSheetId;

  if (!expectedSpreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_ID_MISSING",
      is_retryable: false,
      message: "Spreadsheet ID untuk koneksi '" + connectionKey + "' belum dikonfigurasi di Script Properties."
    }, 400);
  }

  var requestedSpreadsheetId = (payload.spreadsheet_id !== undefined && payload.spreadsheet_id !== null)
    ? payload.spreadsheet_id.toString().trim()
    : "";

  if (requestedSpreadsheetId !== "" && requestedSpreadsheetId !== expectedSpreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "Spreadsheet ID yang diminta ('" + requestedSpreadsheetId + "') tidak sesuai dengan konfigurasi resmi untuk koneksi '" + connectionKey + "'."
    }, 400);
  }

  var spreadsheetId = expectedSpreadsheetId;

  var headerRowIndex = 1;
  if (connectionKey === "registration" || /^\d{4}$/.test(targetTabName)) {
    headerRowIndex = 12;
  }

  var lock = (typeof LockService !== "undefined" && LockService && LockService.getScriptLock)
    ? LockService.getScriptLock()
    : null;

  if (!lock) {
    return createJsonResponse({
      success: true,
      dry_run: true,
      sync_id: syncId,
      resolved_spreadsheet_id: spreadsheetId,
      header_row_index: headerRowIndex,
      message: "Target validation passed; LockService not available in test environment."
    }, 200);
  }

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
    var autoSyncHeaders = (connectionKey === "registration" && payload.auto_sync_headers === true && Array.isArray(payload.schema_headers) && payload.schema_headers.length >= 40);
    var sheet = autoSyncHeaders
      ? ensureSheetAndSyncHeaders(ss, targetTabName, payload.schema_headers, headerRowIndex)
      : ss.getSheetByName(targetTabName);

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

    if (lastCol === 0 || lastRow < headerRowIndex) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "EMPTY_SHEET",
        is_retryable: false,
        message: "Sheet tidak memiliki baris header yang valid pada baris " + headerRowIndex + "."
      }, 400);
    }

    // Read headers on the designated header row (Row 12 for Registration, Row 1 for Follow-Up)
    var actualHeaders = sheet.getRange(headerRowIndex, 1, 1, lastCol).getValues()[0];
    var syncIdColIndex = -1;

    for (var i = 0; i < actualHeaders.length; i++) {
      var hClean = actualHeaders[i].toString().trim();
      if (hClean.toUpperCase() === "SERTIF_SYNC_ID") {
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
        message: "Kolom metadata 'SERTIF_SYNC_ID' tidak ditemukan pada baris " + headerRowIndex + " sheet."
      }, 400);
    }

    // Map row values and strictly validate schema compatibility against contract
    var mappingResult = buildRowValuesForSheet(actualHeaders, rowData, connectionKey, autoSyncHeaders);
    if (mappingResult.error) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: mappingResult.error_code || "SCHEMA_MISMATCH",
        is_retryable: false,
        message: mappingResult.error
      }, 400);
    }

    var rowValues = mappingResult.values;

    // Find existing rows by SERTIF_SYNC_ID starting AFTER the header row
    var matchingRows = [];
    var numDataRows = lastRow - headerRowIndex;
    if (numDataRows > 0) {
      var syncIdValues = sheet.getRange(headerRowIndex + 1, syncIdColIndex, numDataRows, 1).getValues();
      for (var r = 0; r < syncIdValues.length; r++) {
        if (syncIdValues[r][0].toString().trim() === syncId) {
          matchingRows.push(headerRowIndex + 1 + r);
        }
      }
    }

    if (matchingRows.length > 1) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "DUPLICATE_SYNC_ID",
        is_retryable: false,
        message: "Terdeteksi lebih dari satu baris (" + matchingRows.length + ") dengan SERTIF_SYNC_ID '" + syncId + "'. Operasi upsert dihentikan fail-closed."
      }, 409);
    }

    var targetRowIndex = (matchingRows.length === 1) ? matchingRows[0] : -1;
    var operation = "inserted";
    if (targetRowIndex > 0) {
      // UPDATE existing row
      sheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);
      operation = "updated";
    } else {
      // INSERT new row at end (guaranteed after headerRowIndex)
      targetRowIndex = Math.max(lastRow + 1, headerRowIndex + 1);
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
 * Handle Remote Update-Only Action (S17.18B)
 * Strictly updates an existing row. Returns 404 (ROW_NOT_FOUND) with zero rows inserted
 * if the row does not already exist on the target sheet.
 * Fails closed with 409 (DUPLICATE_SYNC_ID) if more than 1 matching row is detected.
 * Fails closed with 409 (IDENTITY_MISMATCH) if remote SERTIF_REGISTRATION_ID differs from payload.
 */
function handleUpdateRowAction(payload) {
  var connectionKey = payload.connection_key;
  var targetTabName = payload.tab_name;
  var syncId = payload.sync_id;
  var rowData = payload.payload || {};

  // 1. Strict validation of sync_id
  if (syncId === undefined || syncId === null || typeof syncId !== "string" || syncId.trim() === "") {
    return createJsonResponse({
      success: false,
      sync_id: syncId || null,
      error_code: "INVALID_SYNC_ID",
      is_retryable: false,
      message: "sync_id wajib berupa string yang tidak kosong."
    }, 400);
  }

  // 2. Strict validation of SERTIF_SYNC_ID inside payload
  var payloadSyncId = undefined;
  if (rowData && typeof rowData === "object") {
    if (rowData.hasOwnProperty("SERTIF_SYNC_ID")) {
      payloadSyncId = rowData["SERTIF_SYNC_ID"];
    } else {
      for (var k in rowData) {
        if (rowData.hasOwnProperty(k) && k.toString().trim().toUpperCase() === "SERTIF_SYNC_ID") {
          payloadSyncId = rowData[k];
          break;
        }
      }
    }
  }

  if (payloadSyncId === undefined || payloadSyncId === null || payloadSyncId.toString().trim() === "") {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "INVALID_SYNC_ID",
      is_retryable: false,
      message: "Field SERTIF_SYNC_ID wajib tersedia dan tidak boleh kosong di dalam payload."
    }, 400);
  }

  if (syncId.toString().trim() !== payloadSyncId.toString().trim()) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SYNC_ID_MISMATCH",
      is_retryable: false,
      message: "sync_id ('" + syncId + "') tidak sesuai dengan nilai SERTIF_SYNC_ID dalam payload ('" + payloadSyncId + "')."
    }, 400);
  }

  // 3. Strict allowlist validation for connection_key
  if (connectionKey !== "registration" && connectionKey !== "followup") {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "connection_key '" + connectionKey + "' tidak valid. Hanya diizinkan 'registration' atau 'followup'."
    }, 400);
  }

  // 4. Strict allowlist validation for tab_name (2020-2099 for registration, explicit string for followup)
  var validTab = false;
  if (connectionKey === "registration") {
    if (typeof targetTabName === "string" && /^\d{4}$/.test(targetTabName.trim())) {
      var yearNum = parseInt(targetTabName.trim(), 10);
      if (yearNum >= 2020 && yearNum <= 2099) {
        validTab = true;
      }
    }
  } else if (connectionKey === "followup" && targetTabName === "Follow Up Guru Dosen") {
    validTab = true;
  }

  if (!validTab) {
    var expectedTab = (connectionKey === "registration") ? "2020-2099 (4 digit)" : "Follow Up Guru Dosen";
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "Tab '" + (targetTabName || "") + "' tidak diizinkan untuk koneksi '" + connectionKey + "'. Tab resmi yang diizinkan hanya '" + expectedTab + "'."
    }, 400);
  }

  // 5. Strict fail-closed Spreadsheet ID resolution from Script Properties
  var props = null;
  try {
    if (typeof PropertiesService !== "undefined" && PropertiesService && PropertiesService.getScriptProperties) {
      props = PropertiesService.getScriptProperties();
    }
  } catch (e) {
    props = null;
  }
  if (!props && typeof global !== "undefined" && global._mockScriptProperties) {
    props = global._mockScriptProperties;
  }

  var regSheetId = "";
  var fwuSheetId = "";
  if (props && typeof props.getProperty === "function") {
    regSheetId = (props.getProperty("REGISTRATION_SPREADSHEET_ID") || "").toString().trim();
    fwuSheetId = (props.getProperty("FOLLOWUP_SPREADSHEET_ID") || "").toString().trim();
  }

  var expectedSpreadsheetId = (connectionKey === "registration") ? regSheetId : fwuSheetId;

  if (!expectedSpreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_ID_MISSING",
      is_retryable: false,
      message: "Spreadsheet ID untuk koneksi '" + connectionKey + "' belum dikonfigurasi di Script Properties."
    }, 400);
  }

  var requestedSpreadsheetId = (payload.spreadsheet_id !== undefined && payload.spreadsheet_id !== null)
    ? payload.spreadsheet_id.toString().trim()
    : "";

  if (requestedSpreadsheetId !== "" && requestedSpreadsheetId !== expectedSpreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "TARGET_MISMATCH",
      is_retryable: false,
      message: "Spreadsheet ID yang diminta ('" + requestedSpreadsheetId + "') tidak sesuai dengan konfigurasi resmi untuk koneksi '" + connectionKey + "'."
    }, 400);
  }

  var spreadsheetId = expectedSpreadsheetId;

  var headerRowIndex = 1;
  if (connectionKey === "registration" || /^\d{4}$/.test(targetTabName)) {
    headerRowIndex = 12;
  }

  var lock = (typeof LockService !== "undefined" && LockService && LockService.getScriptLock)
    ? LockService.getScriptLock()
    : null;

  if (!lock) {
    return createJsonResponse({
      success: true,
      dry_run: true,
      action: "update_row",
      sync_id: syncId,
      resolved_spreadsheet_id: spreadsheetId,
      header_row_index: headerRowIndex,
      message: "Target validation passed; LockService not available in test environment."
    }, 200);
  }

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
    var autoSyncHeaders = (connectionKey === "registration" && payload.auto_sync_headers === true && Array.isArray(payload.schema_headers) && payload.schema_headers.length >= 40);
    var sheet = autoSyncHeaders
      ? ensureSheetAndSyncHeaders(ss, targetTabName, payload.schema_headers, headerRowIndex)
      : ss.getSheetByName(targetTabName);

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

    if (lastCol === 0 || lastRow < headerRowIndex) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "EMPTY_SHEET",
        is_retryable: false,
        message: "Sheet tidak memiliki baris header yang valid pada baris " + headerRowIndex + "."
      }, 400);
    }

    // Read headers on the designated header row
    var actualHeaders = sheet.getRange(headerRowIndex, 1, 1, lastCol).getValues()[0];
    var syncIdColIndex = -1;
    var regIdColIndex = -1;

    for (var i = 0; i < actualHeaders.length; i++) {
      var hClean = actualHeaders[i].toString().trim().toUpperCase();
      if (hClean === "SERTIF_SYNC_ID") {
        syncIdColIndex = i + 1; // 1-based index
      } else if (hClean === "SERTIF_REGISTRATION_ID") {
        regIdColIndex = i + 1;
      }
    }

    if (syncIdColIndex === -1) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "MISSING_SYNC_ID_COLUMN",
        is_retryable: false,
        message: "Kolom metadata 'SERTIF_SYNC_ID' tidak ditemukan pada baris " + headerRowIndex + " sheet."
      }, 400);
    }

    // Scan for all matching rows by SERTIF_SYNC_ID starting AFTER headerRowIndex
    var matchingRows = [];
    var numDataRows = lastRow - headerRowIndex;
    if (numDataRows > 0) {
      var syncIdValues = sheet.getRange(headerRowIndex + 1, syncIdColIndex, numDataRows, 1).getValues();
      for (var r = 0; r < syncIdValues.length; r++) {
        if (syncIdValues[r][0].toString().trim() === syncId) {
          matchingRows.push(headerRowIndex + 1 + r);
        }
      }
    }

    // Guardrail: Row not found -> 404 ROW_NOT_FOUND (ZERO INSERT)
    if (matchingRows.length === 0) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "ROW_NOT_FOUND",
        is_retryable: false,
        message: "Baris dengan SERTIF_SYNC_ID '" + syncId + "' tidak ditemukan pada tab sheet. Operasi update-only menolak penambahan baris baru."
      }, 404);
    }

    // Guardrail: Duplicate sync ID on remote -> 409 DUPLICATE_SYNC_ID (FAIL-CLOSED)
    if (matchingRows.length > 1) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "DUPLICATE_SYNC_ID",
        is_retryable: false,
        message: "Terdeteksi lebih dari satu baris (" + matchingRows.length + ") dengan SERTIF_SYNC_ID '" + syncId + "'. Operasi update-only dihentikan fail-closed."
      }, 409);
    }

    var targetRowIndex = matchingRows[0];

    // Identity verification: ensure registration ID matches remote if column exists
    if (connectionKey === "registration" && regIdColIndex > 0) {
      var remoteRegId = sheet.getRange(targetRowIndex, regIdColIndex, 1, 1).getValue().toString().trim();
      var payloadRegId = (rowData["SERTIF_REGISTRATION_ID"] || "").toString().trim();
      if (remoteRegId !== "" && remoteRegId !== "-" && payloadRegId !== "" && remoteRegId !== payloadRegId) {
        return createJsonResponse({
          success: false,
          sync_id: syncId,
          error_code: "IDENTITY_MISMATCH",
          is_retryable: false,
          message: "Ketidakcocokan identitas registrasi: baris remote memiliki SERTIF_REGISTRATION_ID '" + remoteRegId + "', tetapi payload mengirimkan '" + payloadRegId + "'."
        }, 409);
      }
    }

    // Map row values and strictly validate schema compatibility against contract
    var mappingResult = buildRowValuesForSheet(actualHeaders, rowData, connectionKey, autoSyncHeaders);
    if (mappingResult.error) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: mappingResult.error_code || "SCHEMA_MISMATCH",
        is_retryable: false,
        message: mappingResult.error
      }, 400);
    }

    var rowValues = mappingResult.values;

    // Strictly UPDATE the single matching row
    sheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);

    // Explicitly flush pending changes before releasing script lock
    SpreadsheetApp.flush();

    return createJsonResponse({
      success: true,
      action: "update_row",
      sync_id: syncId,
      row_index: targetRowIndex,
      tab_name: targetTabName,
      operation: "updated",
      timestamp: new Date().toISOString()
    }, 200);
  } catch (err) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_WRITE_ERROR",
      is_retryable: true,
      message: "Gagal memperbarui data di Google Sheet: " + err.toString()
    }, 500);
  } finally {
    lock.releaseLock();
  }
}

/**
 * Handle Automatic Sheet Schema Synchronization (sync_sheet_schema)
 * Creates the target year tab if missing, updates Row 12 headers, and realigns existing rows.
 */
function handleSyncSheetSchemaAction(payload) {
  var connectionKey = payload.connection_key || "registration";
  var targetTabName = (payload.tab_name || "").toString().trim();
  var headers = payload.headers || [];

  if (!Array.isArray(headers) || headers.length === 0) {
    return createJsonResponse({
      success: false,
      error_code: "INVALID_HEADERS",
      is_retryable: false,
      message: "Daftar headers wajib berupa array tidak kosong."
    }, 400);
  }

  var props = null;
  try {
    if (typeof PropertiesService !== "undefined" && PropertiesService && PropertiesService.getScriptProperties) {
      props = PropertiesService.getScriptProperties();
    }
  } catch (e) {}
  if (!props && typeof global !== "undefined" && global._mockScriptProperties) {
    props = global._mockScriptProperties;
  }

  var regSheetId = (props && typeof props.getProperty === "function")
    ? (props.getProperty("REGISTRATION_SPREADSHEET_ID") || "").toString().trim()
    : "";
  var spreadsheetId = (payload.spreadsheet_id || regSheetId).toString().trim();

  if (!spreadsheetId) {
    return createJsonResponse({
      success: false,
      error_code: "SPREADSHEET_ID_MISSING",
      is_retryable: false,
      message: "Spreadsheet ID belum dikonfigurasi."
    }, 400);
  }

  var headerRowIndex = (connectionKey === "registration" || /^\d{4}$/.test(targetTabName)) ? 12 : 1;
  var lock = (typeof LockService !== "undefined" && LockService && LockService.getScriptLock)
    ? LockService.getScriptLock()
    : null;

  var lockAcquired = false;
  if (lock) {
    try { lockAcquired = lock.tryLock(30000); } catch (e) {}
  }

  try {
    var ss = SpreadsheetApp.openById(spreadsheetId);
    ensureSheetAndSyncHeaders(ss, targetTabName, headers, headerRowIndex);
    SpreadsheetApp.flush();

    return createJsonResponse({
      success: true,
      action: "sync_sheet_schema",
      tab_name: targetTabName,
      header_row_index: headerRowIndex,
      column_count: headers.length,
      timestamp: new Date().toISOString()
    }, 200);
  } catch (err) {
    return createJsonResponse({
      success: false,
      error_code: "SPREADSHEET_SCHEMA_SYNC_ERROR",
      is_retryable: true,
      message: "Gagal menyinkronkan skema kolom Spreadsheet: " + err.toString()
    }, 500);
  } finally {
    if (lock && lockAcquired) {
      lock.releaseLock();
    }
  }
}

/**
 * Handle Safe Read-Only Row Lookup Action by sync_id
 */
function handleLookupRowAction(payload) {
  var connectionKey = payload.connection_key;
  var targetTabName = payload.tab_name;
  var syncId = payload.sync_id;

  if (!syncId || typeof syncId !== "string" || syncId.trim() === "") {
    return createJsonResponse({
      success: false,
      error_code: "INVALID_SYNC_ID",
      is_retryable: false,
      message: "sync_id wajib diisi untuk lookup."
    }, 400);
  }

  var props = PropertiesService.getScriptProperties();
  var regSheetId = props.getProperty("REGISTRATION_SPREADSHEET_ID");
  var fwuSheetId = props.getProperty("FOLLOWUP_SPREADSHEET_ID");

  var spreadsheetId = payload.spreadsheet_id;
  if (!spreadsheetId) {
    if (connectionKey === "registration") {
      spreadsheetId = regSheetId;
    } else if (connectionKey === "followup") {
      spreadsheetId = fwuSheetId;
    }
  }

  if (!spreadsheetId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SPREADSHEET_NOT_CONFIGURED",
      is_retryable: false,
      message: "Spreadsheet ID belum dikonfigurasi."
    }, 400);
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
        message: "Tab sheet '" + targetTabName + "' tidak ditemukan."
      }, 404);
    }

    var headerRowIndex = (connectionKey === "registration") ? 12 : 1;
    var lastRow = sheet.getLastRow();
    var lastCol = sheet.getLastColumn();

    if (lastCol === 0 || lastRow < headerRowIndex) {
      return createJsonResponse({
        success: true,
        sync_id: syncId,
        found: false,
        row_index: null,
        message: "Sheet kosong atau belum memiliki data."
      }, 200);
    }

    var actualHeaders = sheet.getRange(headerRowIndex, 1, 1, lastCol).getValues()[0];
    var syncIdColIndex = -1;
    for (var i = 0; i < actualHeaders.length; i++) {
      if (actualHeaders[i].toString().trim().toUpperCase() === "SERTIF_SYNC_ID") {
        syncIdColIndex = i + 1;
        break;
      }
    }

    if (syncIdColIndex === -1) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: "MISSING_SYNC_ID_COLUMN",
        is_retryable: false,
        message: "Kolom SERTIF_SYNC_ID tidak ditemukan pada header."
      }, 400);
    }

    var numDataRows = lastRow - headerRowIndex;
    var matchingRows = [];
    if (numDataRows > 0) {
      var syncIdValues = sheet.getRange(headerRowIndex + 1, syncIdColIndex, numDataRows, 1).getValues();
      for (var r = 0; r < syncIdValues.length; r++) {
        if (syncIdValues[r][0].toString().trim() === syncId.trim()) {
          matchingRows.push(headerRowIndex + 1 + r);
        }
      }
    }

    return createJsonResponse({
      success: true,
      sync_id: syncId,
      found: matchingRows.length > 0,
      row_index: matchingRows.length === 1 ? matchingRows[0] : null,
      duplicate_count: matchingRows.length,
      matching_rows: matchingRows,
      timestamp: new Date().toISOString()
    }, 200);
  } catch (err) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "LOOKUP_ERROR",
      is_retryable: true,
      message: "Gagal melakukan lookup baris: " + err.toString()
    }, 500);
  }
}

/**
 * Handle Idempotent Upload Attachment to Google Drive
 */
function handleUploadAttachmentAction(payload) {
  var props = PropertiesService.getScriptProperties();
  var rootFolderId = (payload.drive_folder_id || props.getProperty("ATTACHMENTS_ROOT_FOLDER_ID") || "1d3TqOQO7x1REOYPNSRlZRLc7nH4K5G3u").toString().trim();

  if (!rootFolderId) {
    return createJsonResponse({
      success: false,
      error_code: "DRIVE_FOLDER_NOT_CONFIGURED",
      is_retryable: false,
      message: "Root folder ID Google Drive belum dikonfigurasi pada pengaturan Integrasi Spreadsheet maupun Script Properties."
    }, 400);
  }

  var syncId = (payload.sync_id || "").toString().trim();
  var year = (payload.year || new Date().getFullYear().toString()).toString().trim();
  var regId = (payload.registration_id || "0").toString().trim();
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

  // S17.18G.6-D-R04 Section 4: Checksum & Version Integrity Verification
  var expectedChecksum = (payload.file_checksum || "").toLowerCase().trim();
  if (!expectedChecksum || !/^[a-f0-9]{64}$/.test(expectedChecksum)) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "INVALID_CHECKSUM",
      is_retryable: false,
      message: "Parameter file_checksum wajib berupa SHA-256 hex string 64 karakter valid."
    }, 400);
  }

  // 1. Decode base64
  var decodedBytes = null;
  try {
    decodedBytes = Utilities.base64Decode(base64Data);
  } catch (decErr) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "BASE64_DECODE_ERROR",
      is_retryable: false,
      message: "Gagal mendecode konten berkas base64: " + decErr.toString()
    }, 400);
  }

  // 2. Compute SHA-256 byte digest using Apps Script crypto utility
  var actualFileChecksum = null;
  try {
    var digestBytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, decodedBytes);
    actualFileChecksum = bytesToHex(digestBytes).toLowerCase();
  } catch (digErr) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "DIGEST_ERROR",
      is_retryable: false,
      message: "Gagal menghitung digest SHA-256 berkas: " + digErr.toString()
    }, 500);
  }

  // 3. Compare computed digest with payload.file_checksum
  if (actualFileChecksum !== expectedChecksum) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "CHECKSUM_MISMATCH",
      is_retryable: false,
      message: "Checksum SHA-256 byte berkas (" + actualFileChecksum + ") tidak cocok dengan payload file_checksum (" + expectedChecksum + ")."
    }, 400);
  }

  // 4. Verify content_version matches prefix of checksum (16 chars)
  var contentVersion = (payload.content_version || "").toLowerCase().trim();
  var expectedVersion = actualFileChecksum.substring(0, 16);
  if (!contentVersion || contentVersion !== expectedVersion) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "CONTENT_VERSION_MISMATCH",
      is_retryable: false,
      message: "Parameter content_version (" + contentVersion + ") tidak sesuai dengan prefix SHA-256 (" + expectedVersion + ")."
    }, 400);
  }

  // 5. Ensure registration_id, attachment_id, and sync_id are bound properly
  var attId = (payload.attachment_id || "").toString().trim();
  if (!attId && syncId) {
    var syncParts = syncId.split("-");
    if (syncParts.length >= 3) {
      attId = syncParts[2];
    }
  }

  if (!regId || regId === "0" || !attId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "INVALID_IDENTITY",
      is_retryable: false,
      message: "registration_id dan attachment_id wajib diisi secara valid."
    }, 400);
  }

  var expectedSyncId = "ATT-" + regId + "-" + attId + "-v" + expectedVersion;
  if (syncId !== expectedSyncId) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "SYNC_IDENTITY_MISMATCH",
      is_retryable: false,
      message: "sync_id (" + syncId + ") tidak terikat ke format deterministik (" + expectedSyncId + ")."
    }, 400);
  }

  if (typeof LockService === "undefined" || !LockService.getScriptLock) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "LOCK_SERVICE_UNAVAILABLE",
      is_retryable: true,
      message: "Layanan LockService Google Apps Script tidak tersedia di lingkungan ini."
    }, 503);
  }

  if (typeof DriveApp === "undefined" || !DriveApp.getFolderById) {
    return createJsonResponse({
      success: false,
      sync_id: syncId,
      error_code: "DRIVE_SERVICE_UNAVAILABLE",
      is_retryable: true,
      message: "Layanan DriveApp Google tidak tersedia di lingkungan ini."
    }, 503);
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
    
    // Subfolder per pendaftar di dalam folder Google Drive yang dikonfigurasi: {ApplicantName}_REG{regId}
    var cleanApplicantName = applicantName.replace(/[^a-zA-Z0-9_\-\s]/g, "").trim().replace(/\s+/g, "_");
    var targetFolderName = cleanApplicantName + "_REG" + regId;
    var targetFolder = null;
    if (payload.direct_applicant_folder || payload.drive_folder_id) {
      targetFolder = getOrCreateSubFolder(rootFolder, targetFolderName);
    } else {
      var baseFolderName = payload.base_folder || "Registrasi";
      var regBaseFolder = getOrCreateSubFolder(rootFolder, baseFolderName);
      var yearFolder = getOrCreateSubFolder(regBaseFolder, year);
      targetFolder = getOrCreateSubFolder(yearFolder, targetFolderName);
    }
    var folderId = (targetFolder && typeof targetFolder.getId === "function") ? targetFolder.getId() : null;
    var folderUrl = (targetFolder && typeof targetFolder.getUrl === "function")
      ? targetFolder.getUrl()
      : (folderId ? "https://drive.google.com/drive/folders/" + folderId : null);

    // Idempotency check: check if file with this sync identity & verified checksum already exists
    var safeFilePrefix = syncId + "_";
    var existingFiles = targetFolder.getFiles();
    while (existingFiles.hasNext()) {
      var file = existingFiles.next();
      var fName = file.getName() || "";
      var desc = (file.getDescription() || "").trim();

      // Check if this remote file matches the exact sync identity & content version
      var isSameVersionIdentity = (fName.indexOf(safeFilePrefix) === 0 ||
                                   desc.indexOf(":sync_id=" + syncId) !== -1 ||
                                   desc.indexOf(syncId) !== -1);

      if (isSameVersionIdentity) {
        // Extract remote checksum from description: "sertif_sync:reg=...:att=...:sha256=...:sync_id=..."
        var remoteChecksum = null;
        var match = desc.match(/sha256=([a-f0-9]{64})/i);
        if (match) {
          remoteChecksum = match[1].toLowerCase();
        }

        // Blocker A (Req 1, 2, 3, 4): Jika identitas versi sama tetapi metadata checksum tidak dapat diverifikasi -> FAIL CLOSED!
        if (!remoteChecksum) {
          return createJsonResponse({
            success: false,
            sync_id: syncId,
            error_code: "REMOTE_IDENTITY_UNVERIFIABLE",
            is_retryable: false,
            message: "Berkas remote dengan identitas versi sama ('" + syncId + "') ditemukan namun metadata checksum tidak dapat diverifikasi."
          }, 422);
        }

        // Blocker A (Req 3): Jika identitas versi sama namun checksum remote berbeda -> FAIL CLOSED (conflict)!
        if (remoteChecksum !== actualFileChecksum) {
          return createJsonResponse({
            success: false,
            sync_id: syncId,
            error_code: "REMOTE_IDENTITY_CONFLICT",
            is_retryable: false,
            message: "Konflik berkas remote: berkas dengan identitas versi sama memiliki checksum remote (" + remoteChecksum + ") yang berbeda dari checksum lokal (" + actualFileChecksum + ")."
          }, 422);
        }

        // Blocker A (Req 6): Identitas sama dan SHA256 terbukti sama -> reuse secara idempotent
        return createJsonResponse({
          success: true,
          action: "upload_attachment",
          sync_id: syncId,
          file_id: file.getId(),
          file_name: file.getName(),
          web_view_link: file.getUrl(),
          folder_id: folderId,
          folder_url: folderUrl,
          operation: "reused_existing",
          timestamp: new Date().toISOString()
        });
      }
    }

    // Decode and save new file version (using verified decodedBytes and verified actualFileChecksum)
    var uniqueFileName = safeFilePrefix + originalFileName;
    var blob = Utilities.newBlob(decodedBytes, mimeType, uniqueFileName);
    
    var createdFile = targetFolder.createFile(blob);
    if (createdFile && typeof createdFile.setDescription === "function") {
      createdFile.setDescription("sertif_sync:reg=" + regId + ":att=" + attId + ":sha256=" + actualFileChecksum + ":sync_id=" + syncId);
    }

    // Keep file permissions strictly restricted (inherit from folder)
    return createJsonResponse({
      success: true,
      action: "upload_attachment",
      sync_id: syncId,
      file_id: createdFile.getId(),
      file_name: createdFile.getName(),
      web_view_link: createdFile.getUrl(),
      folder_id: folderId,
      folder_url: folderUrl,
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
    if (lockAcquired && lock) {
      try {
        lock.releaseLock();
      } catch (e) {}
    }
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

  // 2. HMAC-SHA256 Signature calculation & verification FIRST (R02 Fix 1 & Fix 2)
  // Request dengan signature invalid TIDAK BOLEH mengonsumsi nonce
  var canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
  var expectedSignatureBytes = Utilities.computeHmacSha256Signature(
    canonicalData,
    sharedSecret,
    Utilities.Charset.UTF_8
  );
  var expectedSignatureHex = bytesToHex(expectedSignatureBytes);

  if (signature.toLowerCase() !== expectedSignatureHex.toLowerCase()) {
    return {
      success: false,
      error_code: "INVALID_SIGNATURE",
      is_retryable: false,
      message: "Tanda tangan HMAC-SHA256 tidak valid."
    };
  }

  // 3. Concurrency-safe Replay Attack protection via CacheService & LockService (R03 Final Lock Safety)
  // Nonce hanya diperiksa dan dicatat SETELAH signature terverifikasi valid DAN lock berhasil diperoleh
  if (typeof LockService === "undefined" || !LockService || !LockService.getScriptLock) {
    return {
      success: false,
      error_code: "LOCK_SERVICE_UNAVAILABLE",
      is_retryable: true,
      message: "Layanan LockService tidak tersedia untuk perlindungan konkurensi."
    };
  }

  var nonceLock = null;
  var lockAcquired = false;

  try {
    nonceLock = LockService.getScriptLock();
    if (!nonceLock) {
      return {
        success: false,
        error_code: "LOCK_SERVICE_UNAVAILABLE",
        is_retryable: true,
        message: "Gagal menginisialisasi ScriptLock untuk operasi nonce."
      };
    }

    if (typeof nonceLock.tryLock === "function") {
      lockAcquired = nonceLock.tryLock(5000);
    } else if (typeof nonceLock.waitLock === "function") {
      nonceLock.waitLock(5000);
      lockAcquired = (typeof nonceLock.hasLock === "function") ? nonceLock.hasLock() : true;
    }

    if (!lockAcquired) {
      return {
        success: false,
        error_code: "LOCK_TIMEOUT",
        is_retryable: true,
        message: "Gagal memperoleh lock konkurensi nonce dalam batas waktu 5000ms."
      };
    }
  } catch (lockErr) {
    return {
      success: false,
      error_code: "LOCK_TIMEOUT",
      is_retryable: true,
      message: "Gagal memperoleh lock konkurensi nonce: " + lockErr.toString()
    };
  }

  try {
    if (typeof CacheService === "undefined" || !CacheService || !CacheService.getScriptCache) {
      return {
        success: false,
        error_code: "CACHE_SERVICE_UNAVAILABLE",
        is_retryable: true,
        message: "Layanan CacheService tidak tersedia untuk validasi replay attack."
      };
    }

    var cache = CacheService.getScriptCache();
    if (!cache || typeof cache.get !== "function" || typeof cache.put !== "function") {
      return {
        success: false,
        error_code: "CACHE_SERVICE_UNAVAILABLE",
        is_retryable: true,
        message: "ScriptCache tidak merespon antarmuka cache get/put."
      };
    }

    var cacheKey = "nonce_" + nonce;
    if (cache.get(cacheKey) !== null) {
      return {
        success: false,
        error_code: "REPLAYED_NONCE",
        is_retryable: false,
        message: "Nonce telah digunakan sebelumnya (Replay Attack dicegah)."
      };
    }

    // S17.18G.6-A-R04: Replay Window Alignment
    // Nonce retention period calculation:
    // With timestamp freshness window W = 300s, a request received at T_recv with future clock drift up to
    // T_recv + 300 remains within the freshness window until server time reaches T_recv + 600s (2W).
    // Storing nonce with TTL = 900s (15 minutes) ensures:
    // 1) 900s >= 600s (full validity duration + 300s conservative safety margin).
    // 2) Any replay within 600s is rejected as REPLAYED_NONCE.
    // 3) Any replay after 600s is rejected by freshness check as EXPIRED_REQUEST.
    // 4) Zero overlap window where a replay could ever be accepted.
    cache.put(cacheKey, "1", 900);
  } catch (cacheErr) {
    return {
      success: false,
      error_code: "CACHE_SERVICE_UNAVAILABLE",
      is_retryable: true,
      message: "Terjadi kesalahan pada akses CacheService: " + cacheErr.toString()
    };
  } finally {
    if (lockAcquired && nonceLock) {
      try {
        var holds = (typeof nonceLock.hasLock === "function") ? nonceLock.hasLock() : true;
        if (holds) {
          nonceLock.releaseLock();
        }
      } catch (relErr) {}
    }
  }

  return null; // Valid
}

/**
 * Resolusi spreadsheet target untuk operasi sidecar tab 2026_Custom_Fields.
 */
function resolveSpreadsheetForSidecar(payload) {
  var props = null;
  try {
    if (typeof PropertiesService !== "undefined" && PropertiesService && PropertiesService.getScriptProperties) {
      props = PropertiesService.getScriptProperties();
    }
  } catch (e) {
    props = null;
  }
  if (!props && typeof global !== "undefined" && global._mockScriptProperties) {
    props = global._mockScriptProperties;
  }

  var regSheetId = "";
  if (props && typeof props.getProperty === "function") {
    regSheetId = (props.getProperty("REGISTRATION_SPREADSHEET_ID") || "").toString().trim();
  }

  var targetId = (payload.spreadsheet_id || regSheetId || "").toString().trim();
  if (!targetId) {
    return { error: "Spreadsheet ID belum dikonfigurasi di payload atau Script Properties.", code: "SPREADSHEET_ID_MISSING" };
  }

  try {
    var ss = SpreadsheetApp.openById(targetId);
    return { spreadsheet: ss, spreadsheetId: targetId };
  } catch (err) {
    return { error: "Spreadsheet target (" + targetId + ") tidak dapat dibuka: " + err.toString(), code: "SPREADSHEET_NOT_FOUND" };
  }
}

/**
 * Canonical identity normalization for field_key and extension column headers (S17.18G.6-C-R02).
 * Normalizes case, trims whitespace, maps spaces/dots/hyphens to underscores, collapses consecutive
 * underscores, and removes disallowed punctuation, conforming to FormBuilder field_key standards.
 * Guarantees that 'Ukuran Kaos', 'ukuran_kaos', 'UKURAN_KAOS', and 'Ukuran-Kaos' resolve to the exact same canonical identity 'ukuran_kaos'.
 */
function normalizeCanonicalFieldKey(key) {
  if (key === undefined || key === null) return "";
  var str = key.toString().trim().toLowerCase();
  if (str === "") return "";
  str = str.replace(/[\s\.\-]+/g, "_");
  str = str.replace(/[^a-z0-9_]/g, "");
  str = str.replace(/_+/g, "_");
  str = str.replace(/^_+|_+$/g, "");
  return str;
}

/**
 * Reserved column keys that cannot be added as custom fields in extension tabs (S17.18G.6-C-R02)
 * Derived from SertIF Core and Spreadsheet Reserved Keys specifications.
 */
var RESERVED_EXTENSION_KEYS = [
  "timestamp", "nama_lengkap", "nim", "program_studi", "email", "jenis_kelamin",
  "no_hp", "asal_sekolah", "asal_kampus", "kota_tinggal", "alasan_magang",
  "jenis_magang", "status_saat_ini", "kesiapan_wfo", "dosen_pembimbing",
  "kesanggupan_jadwal", "laptop_sendiri", "alat_dimiliki", "rencana_mulai",
  "durasi_magang", "sumber_info", "instagram", "info_kost", "program_minat",
  "upload_pdf", "ket", "catatan", "no", "nama", "whatsapp", "phone", "divisi",
  "fullname", "student_id", "born_date", "institution_name", "faculty",
  "study_program", "current_city", "gender", "phone_number", "internship_interest",
  "start_date", "end_date", "internship_type", "internship_arrangement",
  "current_status", "wfo_readiness", "schedule_shift_readiness", "has_laptop",
  "planned_duration", "cv_ktp_portofolio_pdf", "profile_photo", "design_software",
  "video_software", "programming_languages", "digital_marketing_type",
  "owned_tools", "supervisor_name", "supervisor_contact", "current_activities",
  "boarding_info", "social_media_instagram", "internship_info_sources",
  "sertif_sync_id", "sertif_registration_id", "sertif_sync_status", "sertif_last_sync_at",
  "form_schema_version"
];

function isReservedExtensionKey(keyName) {
  if (!keyName) return true;
  var cleanKey = normalizeCanonicalFieldKey(keyName);
  if (!cleanKey) return true;
  for (var i = 0; i < RESERVED_EXTENSION_KEYS.length; i++) {
    if (normalizeCanonicalFieldKey(RESERVED_EXTENSION_KEYS[i]) === cleanKey) return true;
  }
  return false;
}

/**
 * Dynamic resolution of main sheet header row and identity column indices (S17.18G.6-C-R02)
 * Supports standard year sheets (headers on row 12) and flat/legacy sheets (headers on row 1).
 * Never assumes hardcoded column indices or falls back to columns 26/27.
 * Strictly requires both SERTIF_SYNC_ID and SERTIF_REGISTRATION_ID on the EXACT SAME row (no cross-row mixing).
 */
function resolveMainSheetHeaderAndIndices(mainSheet, mainSheetName) {
  var lastRow = mainSheet.getLastRow();
  var lastCol = mainSheet.getLastColumn();
  if (lastRow < 1 || lastCol < 1) {
    return {
      error_code: "MAIN_ROW_NOT_FOUND",
      message: "Tab utama '" + mainSheetName + "' kosong. Baris utama tidak ditemukan."
    };
  }

  // Prefer row 12 for standard 4-digit registration year tabs, row 1 for other/flat tabs
  var candidateRows = [];
  if (/^\d{4}$/.test(mainSheetName)) {
    candidateRows = [12, 1];
  } else {
    candidateRows = [1, 12];
  }
  for (var r = 2; r <= Math.min(lastRow, 15); r++) {
    if (candidateRows.indexOf(r) === -1) {
      candidateRows.push(r);
    }
  }

  var resolvedHeaderRow = -1;
  var syncIdCol = -1;
  var regIdCol = -1;

  for (var cIdx = 0; cIdx < candidateRows.length; cIdx++) {
    var rowNum = candidateRows[cIdx];
    if (rowNum > lastRow) continue;
    var rowValues = mainSheet.getRange(rowNum, 1, 1, lastCol).getValues()[0];
    var sCol = -1;
    var rCol = -1;
    for (var col = 0; col < rowValues.length; col++) {
      var hClean = (rowValues[col] !== null && rowValues[col] !== undefined)
        ? rowValues[col].toString().trim()
        : "";
      if (/^sertif_sync_id$/i.test(hClean)) sCol = col + 1;
      if (/^sertif_registration_id$/i.test(hClean)) rCol = col + 1;
    }
    // CONTRACT: BOTH SERTIF_SYNC_ID and SERTIF_REGISTRATION_ID MUST be found on the SAME row!
    if (sCol !== -1 && rCol !== -1) {
      resolvedHeaderRow = rowNum;
      syncIdCol = sCol;
      regIdCol = rCol;
      break;
    }
  }

  // Cross-row mixing is strictly prohibited: if not found on the exact same row, fail-closed!
  if (resolvedHeaderRow === -1 || syncIdCol === -1 || regIdCol === -1) {
    return {
      error_code: "MAIN_HEADER_ROW_NOT_FOUND",
      message: "Tidak ditemukan satu baris header valid yang memuat sekaligus kolom SERTIF_SYNC_ID dan SERTIF_REGISTRATION_ID pada tab utama '" + mainSheetName + "'."
    };
  }

  return {
    headerRow: resolvedHeaderRow,
    dataStartRow: resolvedHeaderRow + 1,
    syncIdCol: syncIdCol,
    regIdCol: regIdCol,
    lastRow: lastRow,
    lastCol: lastCol
  };
}

/**
 * Handle ensure_extension_columns Action (S17.18D.3)
 * Menjamin tab ekstensi (default: 2026_Custom_Fields) dan kolom field_key terbitan tersedia.
 */
function handleEnsureExtensionColumnsAction(payload) {
  var lock = null;
  try {
    if (typeof LockService !== "undefined" && LockService && LockService.getScriptLock) {
      lock = LockService.getScriptLock();
      var acquired = false;
      if (typeof lock.tryLock === "function") {
        acquired = lock.tryLock(15000);
      } else if (typeof lock.waitLock === "function") {
        lock.waitLock(15000);
        acquired = true;
      }
      if (!acquired) {
        throw new Error("Lock not acquired within 15000ms");
      }
    }
  } catch (lockErr) {
    return createJsonResponse({
      success: false,
      error_code: "LOCK_TIMEOUT",
      is_retryable: true,
      message: "Gagal memperoleh lock konkurensi header expansion dalam 15 detik: " + lockErr.toString()
    }, 429);
  }

  try {
    var resolved = resolveSpreadsheetForSidecar(payload);
    if (resolved.error) {
      return createJsonResponse({
        success: false,
        error_code: resolved.code || "SPREADSHEET_NOT_FOUND",
        is_retryable: false,
        message: resolved.error
      }, 404);
    }

    var ss = resolved.spreadsheet;
    var sheetName = payload.sheet_name || "2026_Custom_Fields";
    var sheet = ss.getSheetByName(sheetName);
    var baseColumns = ["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT"];

    var isNewSheet = false;
    if (!sheet) {
      sheet = ss.insertSheet(sheetName);
      isNewSheet = true;
    }

    var lastCol = sheet.getLastColumn();
    var lastRow = sheet.getLastRow();
    var existingHeaders = [];

    if (isNewSheet || lastCol === 0 || lastRow === 0) {
      // Penulisan base columns hanya diizinkan pada sheet baru atau sheet yang masih kosong
      sheet.getRange(1, 1, 1, baseColumns.length).setValues([baseColumns]);
      existingHeaders = baseColumns.slice();
      lastCol = baseColumns.length;
    } else {
      var headerValues = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
      for (var i = 0; i < headerValues.length; i++) {
        existingHeaders.push((headerValues[i] !== null && headerValues[i] !== undefined) ? headerValues[i].toString().trim() : "");
      }

      // Verifikasi kolom dasar A-D pada sheet existing: DILARANG menimpa/mengacak header yang sudah ada!
      // Jika posisi atau identitas base columns tidak sesuai kontrak -> FAIL-CLOSED (422)
      for (var b = 0; b < baseColumns.length; b++) {
        var actualBase = (existingHeaders[b] !== undefined) ? existingHeaders[b] : "";
        if (actualBase !== baseColumns[b]) {
          return createJsonResponse({
            success: false,
            error_code: "BASE_COLUMNS_MISMATCH",
            is_retryable: false,
            message: "Posisi atau identitas base columns pada tab ekstensi '" + sheetName + "' tidak sesuai kontrak (kolom " + (b + 1) + " diharapkan '" + baseColumns[b] + "', ditemukan '" + actualBase + "'). Menolak mutasi untuk melindungi integritas data."
          }, 422);
        }
      }
    }

    // Tambahkan kolom baru yang belum ada di headers (cegah benturan reserved keys & semantic field identity collision)
    var requestedColumns = payload.columns || [];
    var addedColumns = [];
    for (var c = 0; c < requestedColumns.length; c++) {
      var colKey = (requestedColumns[c] !== null && requestedColumns[c] !== undefined)
        ? requestedColumns[c].toString().trim()
        : "";
      if (!colKey) continue;

      // Cegah penambahan reserved base columns atau reserved spreadsheet keys
      if (isReservedExtensionKey(colKey)) continue;

      var canonColKey = normalizeCanonicalFieldKey(colKey);
      if (!canonColKey) continue;

      // Canonical duplicate check terhadap headers yang sudah ada (misal 'Ukuran Kaos' vs 'ukuran_kaos')
      var found = false;
      for (var e = 0; e < existingHeaders.length; e++) {
        if (normalizeCanonicalFieldKey(existingHeaders[e]) === canonColKey) {
          found = true;
          break;
        }
      }
      if (!found) {
        lastCol++;
        sheet.getRange(1, lastCol).setValue(colKey);
        existingHeaders.push(colKey);
        addedColumns.push(colKey);
      }
    }

    return createJsonResponse({
      success: true,
      action: "ensure_extension_columns",
      sheet_name: sheetName,
      total_columns: existingHeaders.length,
      headers: existingHeaders,
      added_columns: addedColumns
    }, 200);
  } catch (err) {
    return createJsonResponse({
      success: false,
      error_code: "INTERNAL_ERROR",
      is_retryable: true,
      message: "Gagal memastikan kolom ekstensi: " + err.toString()
    }, 500);
  } finally {
    if (lock) {
      try {
        var holdsLock = (typeof lock.hasLock === "function") ? lock.hasLock() : true;
        if (holdsLock) {
          lock.releaseLock();
        }
      } catch (e) {}
    }
  }
}

/**
 * Handle upsert_extension_row Action (S17.18D.3)
 * Menuliskan baris ekstensi pada 2026_Custom_Fields dengan verifikasi identitas baris utama terlebih dahulu.
 */
function handleUpsertExtensionRowAction(payload) {
  var lock = null;
  try {
    if (typeof LockService !== "undefined" && LockService && LockService.getScriptLock) {
      lock = LockService.getScriptLock();
      var acquired = false;
      if (typeof lock.tryLock === "function") {
        acquired = lock.tryLock(15000);
      } else if (typeof lock.waitLock === "function") {
        lock.waitLock(15000);
        acquired = true;
      }
      if (!acquired) {
        throw new Error("Lock not acquired within 15000ms");
      }
    }
  } catch (lockErr) {
    return createJsonResponse({
      success: false,
      error_code: "LOCK_TIMEOUT",
      is_retryable: true,
      message: "Gagal memperoleh lock konkurensi sidecar dalam 15 detik: " + lockErr.toString()
    }, 429);
  }

  try {
    var resolved = resolveSpreadsheetForSidecar(payload);
    if (resolved.error) {
      return createJsonResponse({
        success: false,
        error_code: resolved.code || "SPREADSHEET_NOT_FOUND",
        is_retryable: false,
        message: resolved.error
      }, 404);
    }

    var ss = resolved.spreadsheet;
    var syncId = (payload.sync_id || "").toString().trim();
    var registrationId = (payload.registration_id || "").toString().trim();
    var formSchemaVersion = (payload.form_schema_version || "").toString().trim();
    var lastSyncAt = (payload.last_sync_at || new Date().toISOString()).toString().trim();
    var answers = payload.answers || {};

    if (!syncId || !registrationId) {
      return createJsonResponse({
        success: false,
        error_code: "INVALID_PAYLOAD",
        is_retryable: false,
        message: "sync_id dan registration_id wajib disertakan untuk mutasi sidecar."
      }, 400);
    }

    // 1. REMOTE IDENTITY VERIFICATION: Verifikasi Baris Utama secara Dinamis
    var mainSheetName = payload.main_sheet_name || "2026";
    var mainSheet = ss.getSheetByName(mainSheetName);
    if (!mainSheet) {
      return createJsonResponse({
        success: false,
        error_code: "MAIN_SHEET_NOT_FOUND",
        is_retryable: false,
        message: "Tab utama '" + mainSheetName + "' tidak ditemukan pada Spreadsheet."
      }, 404);
    }

    var headerInfo = resolveMainSheetHeaderAndIndices(mainSheet, mainSheetName);
    if (headerInfo.error_code) {
      return createJsonResponse({
        success: false,
        error_code: headerInfo.error_code,
        is_retryable: false,
        message: headerInfo.message
      }, 422);
    }

    var mainLastRow = headerInfo.lastRow;
    var dataStartRow = headerInfo.dataStartRow;
    var mainSyncIdCol = headerInfo.syncIdCol;
    var mainRegIdCol = headerInfo.regIdCol;

    if (mainLastRow < dataStartRow) {
      return createJsonResponse({
        success: false,
        error_code: "MAIN_ROW_NOT_FOUND",
        is_retryable: false,
        message: "Tab utama '" + mainSheetName + "' tidak memiliki data baris registrasi."
      }, 422);
    }

    var numDataRows = mainLastRow - dataStartRow + 1;
    var mainSyncValues = mainSheet.getRange(dataStartRow, mainSyncIdCol, numDataRows, 1).getValues();
    var matchingMainRows = [];
    for (var mr = 0; mr < mainSyncValues.length; mr++) {
      if (mainSyncValues[mr][0] && mainSyncValues[mr][0].toString().trim() === syncId) {
        matchingMainRows.push(mr + dataStartRow);
      }
    }

    if (matchingMainRows.length === 0) {
      return createJsonResponse({
        success: false,
        error_code: "MAIN_ROW_NOT_FOUND",
        is_retryable: false,
        message: "Baris utama dengan Sync ID '" + syncId + "' tidak ditemukan di tab '" + mainSheetName + "'. Menolak mutasi sidecar."
      }, 422);
    }

    if (matchingMainRows.length > 1) {
      return createJsonResponse({
        success: false,
        error_code: "DUPLICATE_MAIN_SYNC_ID",
        is_retryable: false,
        message: "Ditemukan " + matchingMainRows.length + " baris duplikat pada tab utama dengan Sync ID '" + syncId + "'."
      }, 422);
    }

    var verifiedMainRowIndex = matchingMainRows[0];
    var actualMainRegId = mainSheet.getRange(verifiedMainRowIndex, mainRegIdCol).getValue();
    var strActualRegId = (actualMainRegId !== null && actualMainRegId !== undefined) ? actualMainRegId.toString().trim() : "";
    if (strActualRegId === "") {
      return createJsonResponse({
        success: false,
        error_code: "REMOTE_IDENTITY_INCOMPLETE",
        is_retryable: false,
        message: "Kolom SERTIF_REGISTRATION_ID pada baris utama kosong. Identitas baris utama tidak lengkap."
      }, 422);
    }
    if (strActualRegId !== registrationId) {
      return createJsonResponse({
        success: false,
        error_code: "IDENTITY_MISMATCH",
        is_retryable: false,
        message: "Registration ID pada baris utama (" + strActualRegId + ") tidak cocok dengan payload sidecar (" + registrationId + ")."
      }, 422);
    }

    // 2. REMOTE IDENTITY VERIFICATION: Verifikasi Tab Ekstensi SEBELUM MODIFIKASI HEADER (R02 Fix 4)
    var extSheetName = payload.extension_sheet_name || (mainSheetName + "_Custom_Fields");
    var extSheet = ss.getSheetByName(extSheetName);
    var baseColumns = ["SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "FORM_SCHEMA_VERSION", "SERTIF_LAST_SYNC_AT"];

    var matchingSidecarRows = [];
    var extLastRow = 0;
    var extLastCol = 0;

    if (extSheet) {
      extLastRow = extSheet.getLastRow();
      extLastCol = extSheet.getLastColumn();

      // Verifikasi base columns A-D SEBELUM membaca data atau memodifikasi sheet!
      if (extLastCol > 0) {
        var curBaseHeaders = extSheet.getRange(1, 1, 1, Math.min(extLastCol, baseColumns.length)).getValues()[0];
        for (var b = 0; b < baseColumns.length; b++) {
          var actBase = (curBaseHeaders[b] !== undefined && curBaseHeaders[b] !== null)
            ? curBaseHeaders[b].toString().trim()
            : "";
          if (actBase !== baseColumns[b]) {
            return createJsonResponse({
              success: false,
              error_code: "BASE_COLUMNS_MISMATCH",
              is_retryable: false,
              message: "Posisi atau identitas base columns pada tab ekstensi '" + extSheetName + "' tidak sesuai kontrak (kolom " + (b + 1) + " diharapkan '" + baseColumns[b] + "', ditemukan '" + actBase + "'). Menolak mutasi untuk melindungi integritas data."
            }, 422);
          }
        }
      }

      if (extLastRow > 1) {
        var sidecarSyncValues = extSheet.getRange(2, 1, extLastRow - 1, 1).getValues();
        for (var sr = 0; sr < sidecarSyncValues.length; sr++) {
          if (sidecarSyncValues[sr][0] && sidecarSyncValues[sr][0].toString().trim() === syncId) {
            matchingSidecarRows.push(sr + 2);
          }
        }
      }

      // Check duplicate sidecar rows BEFORE modifying any headers
      if (matchingSidecarRows.length > 1) {
        return createJsonResponse({
          success: false,
          error_code: "DUPLICATE_SIDECAR_SYNC_ID",
          is_retryable: false,
          message: "Ditemukan " + matchingSidecarRows.length + " baris duplikat pada tab sidecar dengan Sync ID '" + syncId + "'."
        }, 422);
      }

      // Check sidecar registration ID mismatch BEFORE modifying any headers
      if (matchingSidecarRows.length === 1) {
        var existingSidecarRegId = extSheet.getRange(matchingSidecarRows[0], 2).getValue();
        var strSidecarRegId = (existingSidecarRegId !== null && existingSidecarRegId !== undefined) ? existingSidecarRegId.toString().trim() : "";
        if (strSidecarRegId !== "" && strSidecarRegId !== registrationId) {
          return createJsonResponse({
            success: false,
            error_code: "IDENTITY_MISMATCH",
            is_retryable: false,
            message: "Registration ID pada baris sidecar eksisting (" + strSidecarRegId + ") tidak cocok dengan payload (" + registrationId + ")."
          }, 422);
        }
      }
    }

    // 3. SEMUA PENGECEKAN PRA-SYARAT LOLOS -> BARU INISIALISASI / MODIFIKASI HEADER EKSTENSI
    var isNewExtSheet = false;
    if (!extSheet) {
      extSheet = ss.insertSheet(extSheetName);
      isNewExtSheet = true;
    }

    var extHeaders = [];
    if (isNewExtSheet || extLastCol === 0 || extLastRow === 0) {
      extSheet.getRange(1, 1, 1, baseColumns.length).setValues([baseColumns]);
      extHeaders = baseColumns.slice();
      extLastRow = 1;
      extLastCol = baseColumns.length;
    } else {
      var extHeaderRow = extSheet.getRange(1, 1, 1, extLastCol).getValues()[0];
      for (var h = 0; h < extHeaderRow.length; h++) {
        extHeaders.push((extHeaderRow[h] !== null && extHeaderRow[h] !== undefined) ? extHeaderRow[h].toString().trim() : "");
      }
      // Dilarang menimpa base columns jika sudah ada di sheet!
      for (var b = 0; b < baseColumns.length; b++) {
        if (extHeaders[b] !== baseColumns[b]) {
          return createJsonResponse({
            success: false,
            error_code: "BASE_COLUMNS_MISMATCH",
            is_retryable: false,
            message: "Posisi atau identitas base columns pada tab ekstensi '" + extSheetName + "' tidak sesuai kontrak."
          }, 422);
        }
      }
    }

    var answerKeys = Object.keys(answers);
    for (var k = 0; k < answerKeys.length; k++) {
      var keyName = (answerKeys[k] !== null && answerKeys[k] !== undefined)
        ? answerKeys[k].toString().trim()
        : "";
      if (!keyName) continue;

      if (isReservedExtensionKey(keyName)) continue;

      var canonKey = normalizeCanonicalFieldKey(keyName);
      if (!canonKey) continue;

      var keyFound = false;
      for (var eh = 0; eh < extHeaders.length; eh++) {
        if (normalizeCanonicalFieldKey(extHeaders[eh]) === canonKey) {
          keyFound = true;
          break;
        }
      }
      if (!keyFound) {
        extLastCol++;
        extSheet.getRange(1, extLastCol).setValue(keyName);
        extHeaders.push(keyName);
      }
    }

    // 4. SUSUN ROW VALUES DAN TULIS / PERBARUI BARIS EKSTENSI
    var existingRowValues = [];
    if (matchingSidecarRows.length === 1) {
      var existingRowIndex = matchingSidecarRows[0];
      var currentExtCols = extSheet.getLastColumn();
      if (currentExtCols > 0) {
        existingRowValues = extSheet.getRange(existingRowIndex, 1, 1, currentExtCols).getValues()[0];
      }
    }

    var rowValues = [];
    for (var colIdx = 0; colIdx < extHeaders.length; colIdx++) {
      var headerName = extHeaders[colIdx];
      if (headerName === "SERTIF_SYNC_ID") {
        rowValues.push(syncId);
      } else if (headerName === "SERTIF_REGISTRATION_ID") {
        rowValues.push(registrationId);
      } else if (headerName === "FORM_SCHEMA_VERSION") {
        rowValues.push(formSchemaVersion);
      } else if (headerName === "SERTIF_LAST_SYNC_AT") {
        rowValues.push(lastSyncAt);
      } else {
        var val = answers[headerName];
        if (val === undefined) {
          var canonHeader = normalizeCanonicalFieldKey(headerName);
          for (var ak = 0; ak < answerKeys.length; ak++) {
            if (normalizeCanonicalFieldKey(answerKeys[ak]) === canonHeader) {
              val = answers[answerKeys[ak]];
              break;
            }
          }
        }

        if (matchingSidecarRows.length === 1) {
          // UPDATE: Preserve historical answer when field is omitted from answers (val === undefined)
          if (val === undefined) {
            var priorVal = (existingRowValues.length > colIdx && existingRowValues[colIdx] !== null && existingRowValues[colIdx] !== undefined)
              ? existingRowValues[colIdx]
              : "";
            rowValues.push(priorVal);
          } else if (val === null || val === "") {
            rowValues.push("");
          } else {
            var formattedVal = Array.isArray(val) ? JSON.stringify(val) : val.toString();
            rowValues.push(formattedVal);
          }
        } else {
          // INSERT: New sidecar row
          var finalVal = (val !== null && val !== undefined)
            ? (Array.isArray(val) ? JSON.stringify(val) : val.toString())
            : "";
          rowValues.push(finalVal);
        }
      }
    }

    var targetRowIndex = 0;
    var resultAction = "";

    if (matchingSidecarRows.length === 1) {
      targetRowIndex = matchingSidecarRows[0];
      extSheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);
      resultAction = "updated";
    } else {
      targetRowIndex = extLastRow + 1;
      extSheet.getRange(targetRowIndex, 1, 1, rowValues.length).setValues([rowValues]);
      resultAction = "inserted";
    }

    return createJsonResponse({
      success: true,
      action: resultAction,
      sync_id: syncId,
      registration_id: registrationId,
      sheet_name: extSheetName,
      row_index: targetRowIndex
    }, 200);

  } catch (err) {
    return createJsonResponse({
      success: false,
      error_code: "INTERNAL_ERROR",
      is_retryable: true,
      message: "Gagal memproses upsert sidecar: " + err.toString()
    }, 500);
  } finally {
    if (lock) {
      try {
        var holdsLock = (typeof lock.hasLock === "function") ? lock.hasLock() : true;
        if (holdsLock) {
          lock.releaseLock();
        }
      } catch (e) {}
    }
  }
}

/**
 * Handle inspect_sidecar_alignment Action (S17.18D.3)
 */
function handleInspectSidecarAlignmentAction(payload) {
  try {
    var resolved = resolveSpreadsheetForSidecar(payload);
    if (resolved.error) {
      return createJsonResponse({
        success: false,
        error_code: resolved.code || "SPREADSHEET_NOT_FOUND",
        is_retryable: false,
        message: resolved.error
      }, 404);
    }

    var ss = resolved.spreadsheet;
    var mainSheetName = payload.main_sheet_name || "2026";
    var extSheetName = payload.extension_sheet_name || (mainSheetName + "_Custom_Fields");
    var syncId = (payload.sync_id || "").toString().trim();

    var mainSheet = ss.getSheetByName(mainSheetName);
    var extSheet = ss.getSheetByName(extSheetName);

    if (!mainSheet) {
      return createJsonResponse({
        success: false,
        error_code: "MAIN_SHEET_MISSING",
        is_retryable: false,
        message: "Tab utama '" + mainSheetName + "' tidak ditemukan."
      }, 404);
    }

    var headerInfo = resolveMainSheetHeaderAndIndices(mainSheet, mainSheetName);
    if (headerInfo.error_code) {
      return createJsonResponse({
        success: false,
        sync_id: syncId,
        error_code: headerInfo.error_code,
        is_retryable: false,
        message: headerInfo.message
      }, 422);
    }

    var mainRowFound = null;
    if (syncId && headerInfo.lastRow >= headerInfo.dataStartRow) {
      var numDataRows = headerInfo.lastRow - headerInfo.dataStartRow + 1;
      var mSyncs = mainSheet.getRange(headerInfo.dataStartRow, headerInfo.syncIdCol, numDataRows, 1).getValues();
      for (var mi = 0; mi < mSyncs.length; mi++) {
        if (mSyncs[mi][0] && mSyncs[mi][0].toString().trim() === syncId) {
          mainRowFound = mi + headerInfo.dataStartRow;
          break;
        }
      }
    }

    var sidecarRowFound = null;
    if (syncId && extSheet && extSheet.getLastRow() > 1) {
      var sSyncs = extSheet.getRange(2, 1, extSheet.getLastRow() - 1, 1).getValues();
      for (var si = 0; si < sSyncs.length; si++) {
        if (sSyncs[si][0] && sSyncs[si][0].toString().trim() === syncId) {
          sidecarRowFound = si + 2;
          break;
        }
      }
    }

    var status = "unknown";
    if (mainRowFound && sidecarRowFound) {
      status = "aligned";
    } else if (mainRowFound && !sidecarRowFound) {
      status = "missing_sidecar";
    } else if (!mainRowFound && sidecarRowFound) {
      status = "orphaned_sidecar";
    } else {
      status = "not_found";
    }

    return createJsonResponse({
      success: true,
      sync_id: syncId,
      status: status,
      main_row_index: mainRowFound,
      sidecar_row_index: sidecarRowFound,
      extension_sheet_exists: extSheet !== null
    }, 200);
  } catch (err) {
    return createJsonResponse({
      success: false,
      error_code: "INTERNAL_ERROR",
      is_retryable: true,
      message: "Gagal inspect sidecar alignment: " + err.toString()
    }, 500);
  }
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
  if (typeof ContentService !== "undefined" && ContentService && ContentService.createTextOutput) {
    var output = ContentService.createTextOutput(JSON.stringify(obj));
    output.setMimeType(ContentService.MimeType.JSON);
    return output;
  }
  return {
    statusCode: statusCode || 200,
    body: JSON.stringify(obj),
    data: obj
  };
}

// Node.js test environment interoperability export
if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    doPost: doPost,
    validateAuthentication: validateAuthentication,
    REG_CONTRACT_2026: REG_CONTRACT_2026,
    FOLLOWUP_CONTRACT: FOLLOWUP_CONTRACT,
    matchRegistrationSlot: matchRegistrationSlot,
    matchFollowUpSlot: matchFollowUpSlot,
    buildRowValuesForSheet: buildRowValuesForSheet,
    handleUpsertRowAction: handleUpsertRowAction,
    handleUpdateRowAction: handleUpdateRowAction,
    handleEnsureExtensionColumnsAction: handleEnsureExtensionColumnsAction,
    handleUpsertExtensionRowAction: handleUpsertExtensionRowAction,
    handleInspectSidecarAlignmentAction: handleInspectSidecarAlignmentAction,
    resolveSpreadsheetForSidecar: resolveSpreadsheetForSidecar,
    normalizeCanonicalFieldKey: normalizeCanonicalFieldKey,
    isReservedExtensionKey: isReservedExtensionKey,
    resolveMainSheetHeaderAndIndices: resolveMainSheetHeaderAndIndices,
    handleUploadAttachmentAction: handleUploadAttachmentAction,
    createJsonResponse: createJsonResponse,
    bytesToHex: bytesToHex
  };
}
