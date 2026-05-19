/**
 * Format date → dd/MM/yyyy (zero-padded, đúng định dạng DKKD yêu cầu)
 */
export function formatDate(date: Date): string {
  const dd = String(date.getDate()).padStart(2, "0");
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const yyyy = date.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}

/**
 * Hôm qua: dd/MM/yyyy
 */
export const getStartDay = () => formatDate(new Date(Date.now() - 1000 * 60 * 60 * 24));

/**
 * Hôm nay: dd/MM/yyyy
 */
export const getEndDay = () => formatDate(new Date());

/**
 * Tính fromDate/toDate từ số ngày đổ lại
 * daysBack=1 → hôm qua đến hôm nay
 * daysBack=3 → 3 ngày trước đến hôm nay
 */
export function buildDateRange(daysBack: number): [string, string] {
  const from = new Date(Date.now() - 1000 * 60 * 60 * 24 * (daysBack - 1));
  from.setHours(0, 0, 0, 0);
  return [formatDate(from), formatDate(new Date())];
}

export const generateDownloadDir = () => {
  const now = new Date();
  const folderName = [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, "0"),
    String(now.getDate()).padStart(2, "0"),
    "-",
    String(now.getHours()).padStart(2, "0"),
    String(now.getMinutes()).padStart(2, "0"),
    String(now.getSeconds()).padStart(2, "0"),
  ].join("");

  return `downloads/${folderName}`;
};
