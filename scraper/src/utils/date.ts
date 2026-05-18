export const getStartDay = () => new Date(Date.now() - 1000 * 60 * 60 * 24).toLocaleDateString('vi-VN');
export const getEndDay = () => new Date().toLocaleDateString('vi-VN');

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
