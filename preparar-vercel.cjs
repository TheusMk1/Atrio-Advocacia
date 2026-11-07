const fs = require("node:fs");
const path = require("node:path");

const root = fs.realpathSync(__dirname);
const output = path.join(root, "dist");
const pages = ["index.html", "escritorio.html", "atuacao.html", "contato.html"];
const assets = [
  "arquitetura.webp", "escritorio.webp", "simbolo.svg",
  "site.css", "site.js", "conteudo.js"
];

if (fs.existsSync(output) && fs.realpathSync(output) !== output) {
  throw new Error("A pasta dist deve ficar dentro do projeto.");
}
fs.rmSync(output, { recursive: true, force: true });
fs.mkdirSync(path.join(output, "assets", "midia"), { recursive: true });

for (const name of pages) {
  const source = path.join(root, name);
  const target = path.join(output, name);
  if (name === "contato.html") {
    const html = fs.readFileSync(source, "utf8")
      .replace('action="enviar.php"', 'action="#formulario" data-static')
      .replace('type="submit">Enviar solicitação', 'type="submit" disabled>Enviar solicitação');
    fs.writeFileSync(target, html);
  } else {
    fs.copyFileSync(source, target);
  }
}
for (const asset of assets) {
  fs.copyFileSync(path.join(root, "assets", asset), path.join(output, "assets", asset));
}
const media = path.join(root, "assets", "midia");
for (const file of fs.readdirSync(media, { withFileTypes: true })) {
  if (file.isFile() && /^[a-z0-9_-]+\.(jpe?g|png|webp)$/i.test(file.name)) {
    fs.copyFileSync(path.join(media, file.name), path.join(output, "assets", "midia", file.name));
  }
}

console.log("Versão estática criada em dist.");
