import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const workingDir = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"));
const workbookPath = path.join(workingDir, "Shout Test Cases.xlsx");
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(workbookPath));
const sheetNames = Array.from(workbook.worksheets).map((sheet) => sheet.name);
const wordpressSheetName = sheetNames.find((name) => /wordpress/i.test(name));
const shouldWrite = process.argv.includes("--write");

if (!wordpressSheetName) {
  throw new Error("WordPress test-case sheet was not found.");
}

const progressiveUpdates = {
  "WP-PRO-001": {
    5: "Valid QA contact/opportunity IDs; GHL configured; relevant Contact product fields and retained Opportunity fields exist.",
    9: "Mapped non-empty product fields are updated on the Contact; strong lead is added and lead is removed only after all GHL field writes succeed.",
    10: "Only submitted retained fields (opportunity.item_finish, opportunity.item_type, and opportunity.venue_size) are updated.",
    11: "The unchanged progressive webhook is sent after the Contact and Opportunity updates; success is logged.",
    12: "No product-specific Opportunity custom field, appointment, or WordPress file is created.",
  },
  "WP-PRO-002": {
    2: "All supported non-file fields map to the correct Contact and Opportunity destinations",
    5: "All progressive HTML/REST Contact product fields and the three retained Opportunity custom fields exist in GHL.",
    6: "Populate quantities, sizes/types, notes, dance-floor finish/type, and venue size; do not attach files.",
    8: "Success response lists the mapped contact.* keys and the three submitted opportunity.* keys.",
    9: "Each non-empty product field lands in its exact Contact custom field; blank product fields are omitted and existing values remain unchanged.",
    10: "Only opportunity.item_finish, opportunity.item_type, and opportunity.venue_size are updated when submitted.",
    11: "Webhook payload remains unchanged: IDs, venue size, product type/finish, and configured size/notes fields.",
    12: "Legacy product-specific Opportunity values are neither updated nor cleared.",
  },
  "WP-PRO-003": {
    5: "Valid IDs; target Contact File Upload custom field exists; WordPress uploads are writable.",
    9: "Standard Contact fields update first; the Contact inspiration field is cleared and then set to the new public WordPress upload URL.",
    10: "Retained Opportunity fields update only after all Contact writes succeed.",
    11: "File is stored in WordPress with a unique timestamp/random suffix; webhook is sent after both destinations succeed.",
    12: "No legacy product-specific Opportunity file field is updated.",
  },
  "WP-PRO-004": {
    5: "Contact already has a file value in the target inspiration custom field.",
    9: "Existing Contact file-field value is cleared, then replaced with the new upload URL.",
    10: "Legacy Opportunity inspiration values remain unchanged.",
  },
  "WP-PRO-005": {
    6: "Only IDs or fields that do not map to configured Contact or retained Opportunity custom fields.",
    9: "No Contact custom-field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-006": {
    9: "No Contact custom-field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-007": {
    9: "No Contact custom-field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-008": {
    9: "No Contact custom-field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-009": {
    9: "No Contact custom-field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-010": {
    2: "Contact custom-field update fails",
    5: "Valid IDs; force the first GHL Contact custom-field update to fail.",
    6: "Mapped non-file product details.",
    8: "HTTP 502 error: 'GHL contact could not be updated.'",
    9: "Contact product changes are not confirmed; no tag update occurs.",
    10: "Opportunity update is not attempted.",
    11: "Contact failure is logged.",
    12: "No webhook or appointment occurs.",
  },
  "WP-PRO-011": {
    2: "Standard Contact fields save but file replacement fails",
    5: "Submit standard and file fields; standard Contact update and file clear succeed; final Contact file write fails.",
    9: "Standard Contact fields remain updated; the failed file field may remain cleared.",
    10: "Opportunity update is not attempted.",
    11: "Uploaded WordPress file remains; failure is logged.",
    12: "No tag changes, webhook, rollback, or appointment occurs.",
  },
  "WP-PRO-012": {
    2: "Contact file-field clear fails",
    3: "P0",
    4: "Partial Success",
    5: "Standard Contact fields save; force the Contact file-field clear request to fail.",
    8: "HTTP 502 error: 'GHL contact could not be updated.'",
    9: "Standard Contact fields remain updated; existing inspiration value may remain; replacement is not attempted.",
    10: "Opportunity update is not attempted.",
    11: "Uploaded WordPress file remains and the clear failure is logged.",
    12: "No tag changes, webhook, rollback, or appointment occurs.",
  },
  "WP-PRO-013": {
    5: "Contact and retained Opportunity updates succeed; force add-tag call to fail.",
    9: "Product fields remain updated; strong lead may be absent; removal of lead is still attempted.",
    10: "Retained Opportunity fields remain updated.",
    12: "Successful Contact and Opportunity updates are not rolled back.",
  },
  "WP-PRO-014": {
    5: "Contact and retained Opportunity updates and add-tag succeed; force remove-tag call to fail.",
    9: "Product fields remain updated; strong lead is added; lead may remain.",
    10: "Retained Opportunity fields remain updated.",
    12: "Successful field writes and the strong-lead tag are not rolled back.",
  },
  "WP-PRO-015": {
    5: "Contact, retained Opportunity, and tag calls succeed; force webhook network/WP_Error.",
    9: "Contact product fields and tag changes remain applied.",
    10: "Retained Opportunity fields remain updated.",
  },
  "WP-PRO-016": {
    5: "Contact, retained Opportunity, and tag calls succeed; webhook returns non-2xx.",
    9: "Contact product fields and tag changes remain applied.",
    10: "Retained Opportunity fields remain updated.",
  },
  "WP-PRO-017": {
    5: "Relevant Contact and retained Opportunity custom-field definitions cannot be resolved.",
    9: "No Contact field or tag update occurs.",
    10: "No Opportunity update occurs.",
  },
  "WP-PRO-018": {
    9: "The same Contact product fields are written again; add/remove tag calls are repeated.",
    10: "The same retained Opportunity fields are written again when included.",
  },
  "WP-PRO-019": {
    9: "Contact file field is cleared and set again to a new unique upload URL; tag operations repeat.",
    10: "No legacy product-specific Opportunity file field is updated.",
  },
  "WP-PRO-020": {
    8: "Request can succeed when the client bypasses the single-file HTML constraint.",
    9: "Only the first uploaded URL is placed in the Contact inspiration field; tags run after all field updates.",
    10: "No legacy product-specific Opportunity file field is updated.",
  },
};

const newProgressiveCases = [
  [
    "WP-PRO-021",
    "Progressive Form (HTML/REST)",
    "Dance Floor Wraps map to exact Contact fields",
    "P0",
    "Positive",
    "The five Dance Floor Wraps Contact fields exist, including both File Upload fields.",
    "Quantity, size, two inspiration files, and notes.",
    "Submit only the Dance Floor Wraps section.",
    "HTTP success lists all five mapped contact.* keys.",
    "Values land in contact.floor_wrap_quantity, contact.floor_wrap_size, contact.dance_floor_inspiration_1, contact.dance_floor_inspiration_2, and contact.floor_wrap_notes.",
    "Legacy opportunity.floor_wrap_* fields remain unchanged.",
    "Both files are uploaded; webhook payload remains unchanged.",
    "No unrelated product fields are updated.",
    "Not Run",
    "",
    "",
    "",
  ],
  [
    "WP-PRO-022",
    "Progressive Form (HTML/REST)",
    "Aisles map to exact Contact fields",
    "P0",
    "Positive",
    "The five Aisles Contact fields exist, including both File Upload fields.",
    "Quantity, size, two inspiration files, and notes.",
    "Submit only the Aisles section.",
    "HTTP success lists all five mapped contact.* keys.",
    "Values land in contact.aisle_quantity, contact.aisle_size, contact.aisle_inspiration_1, contact.aisle_inspiration_2, and contact.aisle_notes.",
    "Legacy opportunity.aisle_* fields remain unchanged.",
    "Both files are uploaded; webhook payload remains unchanged.",
    "No unrelated product fields are updated.",
    "Not Run",
    "",
    "",
    "",
  ],
  [
    "WP-PRO-023",
    "Progressive Form (HTML/REST)",
    "Breathtaking Enhancements map to exact Contact fields",
    "P0",
    "Positive",
    "The five Breathtaking Enhancements Contact fields exist, including both File Upload fields.",
    "Quantity, size/type, two inspiration files, and notes.",
    "Submit only the Breathtaking Enhancements section.",
    "HTTP success lists all five mapped contact.* keys.",
    "Values land in contact.breathtaking_enhancements_quantity, contact.breathtaking_enhancements_size, contact.breathtaking_enhancements_inspiration_1, contact.breathtaking_enhancements_inspiration_2, and contact.breathtaking_enhancement_note.",
    "Legacy opportunity.breathtaking_enhancements_* fields remain unchanged.",
    "Both files are uploaded; webhook payload remains unchanged.",
    "No unrelated product fields are updated.",
    "Not Run",
    "",
    "",
    "Verify the note key is singular: breathtaking_enhancement_note.",
  ],
  [
    "WP-PRO-024",
    "Progressive Form (HTML/REST)",
    "Specialized Touches map to exact Contact fields",
    "P0",
    "Positive",
    "The five Specialized Touches Contact fields exist, including both File Upload fields.",
    "Quantity, size/details, two inspiration files, and notes.",
    "Submit only the Specialized Touches section.",
    "HTTP success lists all five mapped contact.* keys.",
    "Values land in contact.specialized_touches_quantity, contact.specialized_touches_size, contact.specialized_touches_inspiration_1, contact.specialized_touches_inspiration_2, and contact.specialized_touches_note.",
    "Legacy opportunity.specialized_touches_* fields remain unchanged.",
    "Both files are uploaded; webhook payload remains unchanged.",
    "No unrelated product fields are updated.",
    "Not Run",
    "",
    "",
    "Verify the note key is singular: specialized_touches_note.",
  ],
  [
    "WP-PRO-025",
    "Progressive Form (HTML/REST)",
    "Individualized Accents map to exact Contact fields",
    "P0",
    "Positive",
    "The five Individualized Accents Contact fields exist, including both File Upload fields.",
    "Quantity, size/details, two inspiration files, and notes.",
    "Submit only the Individualized Accents section.",
    "HTTP success lists all five mapped contact.* keys.",
    "Values land in contact.individualized_accents_quantity, contact.individualized_accents_size, contact.individualized_accents_inspiration_1, contact.individualized_accents_inspiration_2, and contact.individualized_accents_note.",
    "Legacy opportunity.individualized_accents_* fields remain unchanged.",
    "Both files are uploaded; webhook payload remains unchanged.",
    "No unrelated product fields are updated.",
    "Not Run",
    "",
    "",
    "Verify the note key is singular: individualized_accents_note.",
  ],
  [
    "WP-PRO-026",
    "Progressive Form (HTML/REST)",
    "Only three general fields remain on Opportunity",
    "P0",
    "Regression",
    "Contact product fields and the three retained Opportunity fields exist; legacy Opportunity product fields contain known values.",
    "Submit product details plus dance_floor_wrap_finish, dance_floor_wrap_type, and venue_room_size.",
    "Submit the Progressive Form once.",
    "HTTP success lists Contact product keys plus opportunity.item_finish, opportunity.item_type, and opportunity.venue_size.",
    "Product details update on the Contact.",
    "Only opportunity.item_finish, opportunity.item_type, and opportunity.venue_size update; legacy product-specific Opportunity values remain unchanged.",
    "Webhook payload remains unchanged and is sent after both updates.",
    "No legacy Opportunity product field is cleared or overwritten.",
    "Not Run",
    "",
    "",
    "",
  ],
  [
    "WP-PRO-027",
    "Progressive Form (HTML/REST)",
    "Contact succeeds but retained Opportunity update fails",
    "P0",
    "Partial Success",
    "Contact update succeeds; force the retained Opportunity update to fail.",
    "Valid product details plus one retained Opportunity field.",
    "Submit the Progressive Form once.",
    "HTTP 502 error: 'GHL opportunity could not be updated.'",
    "Contact product fields remain saved because Contact is updated first; no tag changes occur.",
    "Retained Opportunity change is not confirmed.",
    "Opportunity failure is logged.",
    "No rollback, webhook, or appointment occurs.",
    "Not Run",
    "",
    "",
    "Retry is safe but repeats the Contact write.",
  ],
  [
    "WP-PRO-028",
    "Progressive Form (HTML/REST)",
    "Blank product fields preserve existing Contact values",
    "P0",
    "Regression",
    "Contact has existing values in product fields; at least one other mapped field will be submitted non-empty.",
    "Leave one visible or hidden product field blank; populate another mapped product field.",
    "Submit the Progressive Form once.",
    "HTTP success lists only the non-empty mapped field.",
    "Blank product field is omitted and its existing Contact value remains; the non-empty field updates normally.",
    "No legacy product-specific Opportunity field is changed.",
    "Tags and webhook run after successful mapped updates.",
    "No blank value clears stored Contact data.",
    "Not Run",
    "",
    "",
    "",
  ],
];

if (shouldWrite) {
  const sheet = workbook.worksheets.getItem(wordpressSheetName);
  const table = sheet.tables.items[0];

  if (!table) {
    throw new Error("Test-case table was not found.");
  }

  let usedRows = sheet.getUsedRange(true).values.length;
  let data = sheet.getRange(`A6:Q${usedRows}`).values;
  const existingIds = new Set(data.map((row) => String(row[0] ?? "")));
  const missingCases = newProgressiveCases.filter((row) => !existingIds.has(row[0]));

  if (missingCases.length) {
    table.rows.add(null, missingCases);
  }

  usedRows = sheet.getUsedRange(true).values.length;
  data = sheet.getRange(`A6:Q${usedRows}`).values;

  for (const row of data) {
    const updates = progressiveUpdates[String(row[0] ?? "")];
    if (!updates) {
      continue;
    }

    for (const [columnIndex, value] of Object.entries(updates)) {
      row[Number(columnIndex)] = value;
    }
  }

  sheet.getRange(`A6:Q${usedRows}`).values = data;
  sheet.getRange(`A6:Q${usedRows}`).format.rowHeightPx = 96;
  sheet.getRange(`B6:B${usedRows}`).dataValidation = {
    rule: {
      type: "list",
      values: ["Initial Form", "Progressive Form (HTML/REST)", "Schedule Appointment Form"],
    },
  };
  sheet.getRange(`D6:D${usedRows}`).dataValidation = {
    rule: { type: "list", values: ["P0", "P1", "P2"] },
  };
  sheet.getRange(`E6:E${usedRows}`).dataValidation = {
    rule: {
      type: "list",
      values: [
        "Positive",
        "Negative",
        "Partial Success",
        "Resilience",
        "Duplicate Submission",
        "Risk",
        "Regression",
      ],
    },
  };
  sheet.getRange(`N6:N${usedRows}`).dataValidation = {
    rule: {
      type: "list",
      values: ["Not Run", "Pass", "Fail", "Blocked", "Not Applicable"],
    },
  };

  sheet.getRange(`N6:N${usedRows}`).conditionalFormats.deleteAll();
  sheet.getRange(`N6:N${usedRows}`).conditionalFormats.add("containsText", {
    text: "Pass",
    format: { fill: "#E6F4EA", font: { color: "#137333", bold: true } },
  });
  sheet.getRange(`N6:N${usedRows}`).conditionalFormats.add("containsText", {
    text: "Fail",
    format: { fill: "#FCE8E6", font: { color: "#C5221F", bold: true } },
  });
  sheet.getRange(`N6:N${usedRows}`).conditionalFormats.add("containsText", {
    text: "Blocked",
    format: { fill: "#FEF7E0", font: { color: "#B06000", bold: true } },
  });
  sheet.getRange(`D6:D${usedRows}`).conditionalFormats.deleteAll();
  sheet.getRange(`D6:D${usedRows}`).conditionalFormats.add("containsText", {
    text: "P0",
    format: { fill: "#FCE8E6", font: { color: "#C5221F", bold: true } },
  });

  await fs.writeFile(
    path.join(workingDir, "progressive-google-payload.json"),
    JSON.stringify({
      existingCases: sheet.getRange("A21:Q40").values,
      newCases: sheet.getRange("A54:Q61").values,
    }),
  );

  const exported = await SpreadsheetFile.exportXlsx(workbook);
  await exported.save(workbookPath);
}

const summary = await workbook.inspect({
  kind: "workbook,sheet,table",
  maxChars: 12000,
  tableMaxRows: 8,
  tableMaxCols: 18,
  tableMaxCellChars: 120,
});

const wordpressRows = await workbook.inspect({
  kind: "table",
  sheetId: wordpressSheetName,
  range: "A1:R90",
  include: "values,formulas",
  tableMaxRows: 90,
  tableMaxCols: 18,
  tableMaxCellChars: 300,
  maxChars: 50000,
});

const relevantMatches = await workbook.inspect({
  kind: "match",
  searchTerm: "contact\\.|opportunity\\.|Progressive Form|HTML/REST|floor_wrap",
  options: { useRegex: true, maxResults: 300 },
  summary: "progressive form coverage",
  maxChars: 30000,
});

for (const sheetName of sheetNames) {
  const preview = await workbook.render({
    sheetName,
    autoCrop: "all",
    scale: 1,
    format: "png",
  });
  const safeName = sheetName.replace(/[^a-z0-9]+/gi, "-").toLowerCase();
  await fs.writeFile(
    path.join(workingDir, `${shouldWrite ? "after" : "before"}-${safeName}.png`),
    new Uint8Array(await preview.arrayBuffer()),
  );
}

process.stdout.write(
  [
    summary.ndjson,
    wordpressRows.ndjson,
    relevantMatches.ndjson,
  ].join("\n"),
);
