"""Independent standard-library CSV and OOXML verification; synthetic fixtures only."""
import csv, json, sys, zipfile, xml.etree.ElementTree as ET
path, kind = sys.argv[1:]
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
if kind == 'csv':
    with open(path, encoding='utf-8-sig', newline='') as file:
        reader = csv.DictReader(file)
        reader.fieldnames = [v.split(' · ')[0] for v in reader.fieldnames]
        records = list(reader)
    metadata = [r for r in records if r['_row_kind'] == 'metadata']
    assert len(metadata) == 1
    rows = [r for r in records if r['_row_kind'] == 'data']
    headers = list(rows[0] if rows else metadata[0])
    result = {'metadata': json.loads(metadata[0]['report_metadata'])}
else:
    with zipfile.ZipFile(path) as archive:
        assert '[Content_Types].xml' in archive.namelist()
        assert not any('externalLinks/' in n or 'vbaProject' in n for n in archive.namelist())
        root = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        assert root.find('.//s:pane', ns) is not None
        assert root.find('.//s:autoFilter', ns) is not None
        values = []
        for row in root.findall('.//s:sheetData/s:row', ns):
            cells = []
            for cell in row.findall('s:c', ns):
                assert cell.find('s:f', ns) is None
                cells.append(''.join(cell.itertext()) if cell.get('t') == 'inlineStr' else (cell.findtext('s:v', default='', namespaces=ns)))
            values.append(cells)
        headers = [v.split(' · ')[0] for v in values[0]]
        rows = [dict(zip(headers, r)) for r in values[1:]]
        result = {'sheet_count':len(ET.fromstring(archive.read('xl/workbook.xml')).findall('.//s:sheet', ns))}
result.update(rows=len(rows), headers=headers)
for key in ['gross_minor','reversed_minor','net_minor','balance_minor']:
    if key in headers: result[key] = str(sum(int(r[key]) for r in rows))
print(json.dumps(result, ensure_ascii=False))
