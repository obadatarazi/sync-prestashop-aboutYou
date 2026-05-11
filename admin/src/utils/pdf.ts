import { jsPDF } from 'jspdf'

export function exportTablePdf(
  title: string,
  filename: string,
  headers: string[],
  rows: string[][],
): void {
  const doc = new jsPDF({ orientation: 'landscape' })
  doc.setFontSize(14)
  doc.text(title, 14, 16)
  const startY = 24
  const lineH = 7
  const colW = 190 / Math.max(1, headers.length)
  let y = startY
  doc.setFontSize(9)
  doc.setFont('helvetica', 'bold')
  headers.forEach((h, i) => doc.text(h, 14 + i * colW, y))
  y += lineH
  doc.setFont('helvetica', 'normal')
  for (const row of rows) {
    if (y > 190) {
      doc.addPage()
      y = 16
    }
    row.forEach((cell, i) => {
      const text = cell.length > 40 ? `${cell.slice(0, 37)}…` : cell
      doc.text(text, 14 + i * colW, y)
    })
    y += lineH
  }
  doc.save(filename.endsWith('.pdf') ? filename : `${filename}.pdf`)
}
