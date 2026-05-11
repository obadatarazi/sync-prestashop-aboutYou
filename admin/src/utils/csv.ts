export function exportRowsToCsv(filename: string, headers: string[], rows: string[][]) {
  const esc = (cell: string) => {
    if (cell.includes('"') || cell.includes(',') || cell.includes('\n')) {
      return `"${cell.replaceAll('"', '""')}"`
    }
    return cell
  }
  const lines = [headers.map(esc).join(','), ...rows.map((r) => r.map(esc).join(','))]
  const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename.endsWith('.csv') ? filename : `${filename}.csv`
  a.click()
  URL.revokeObjectURL(url)
}
