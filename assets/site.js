(() => {
  const content = window.ATRIO;
  const menu = document.querySelector(".menu");
  const navigation = document.getElementById("navegacao");
  document.documentElement.classList.add("menu-ready");
  const closeMenu = () => {
    navigation.classList.remove("is-open");
    menu.setAttribute("aria-expanded", "false");
    menu.setAttribute("aria-label", "Abrir menu");
  };
  menu.addEventListener("click", () => {
    if (navigation.classList.contains("is-open")) {
      closeMenu();
      return;
    }
    navigation.classList.add("is-open");
    menu.setAttribute("aria-expanded", "true");
    menu.setAttribute("aria-label", "Fechar menu");
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && navigation.classList.contains("is-open")) {
      closeMenu();
      menu.focus();
    }
  });
  document.addEventListener("click", (event) => {
    if (!event.target.closest(".nav-inner")) closeMenu();
  });
  document.querySelectorAll("[data-year]").forEach((node) => {
    node.textContent = new Date().getFullYear();
  });
  if (!content) return;

  const value = (path) => path.split(".").reduce((node, key) => node?.[key], content);
  document.querySelectorAll("[data-text]").forEach((node) => {
    const text = value(node.dataset.text);
    if (typeof text === "string") node.textContent = text;
  });
  document.querySelectorAll("[data-image]").forEach((image) => {
    const item = value(image.dataset.image);
    if (!item?.image) return;
    image.src = item.image;
    image.alt = item.alt || item.name || "";
    image.hidden = false;
    const monogram = image.parentElement.querySelector("[data-initials]");
    if (monogram) monogram.hidden = true;
  });
  document.querySelectorAll("[data-initials]").forEach((node) => {
    const name = content.team[node.dataset.initials]?.name.trim();
    if (name) node.textContent = name.split(/\s+/).slice(0, 2).map((word) => [...word][0]).join("");
  });
  const channels = {
    email: content.demo || !content.email ? "" : "mailto:" + content.email,
    phone: content.phone ? "tel:" + content.phone.replace(/[^+\d]/g, "") : "",
    whatsapp: content.whatsapp ? "https://wa.me/" + content.whatsapp.replace(/\D/g, "") : "",
    instagram: content.instagram,
    linkedin: content.linkedin
  };
  document.querySelectorAll("[data-channel]").forEach((link) => {
    const target = channels[link.dataset.channel];
    if (target) {
      link.href = target;
      link.hidden = false;
      if (target.startsWith("https://")) {
        link.target = "_blank";
        link.rel = "noopener noreferrer";
      }
    } else if (link.dataset.channel === "email") {
      link.removeAttribute("href");
    }
  });
  document.querySelectorAll("[data-social]").forEach((node) => {
    node.hidden = !channels.instagram && !channels.linkedin;
  });
  document.querySelectorAll("[data-demo]").forEach((node) => {
    node.hidden = !content.demo;
  });
  const missingPhone = document.querySelector("[data-missing=phone]");
  if (missingPhone) missingPhone.hidden = Boolean(channels.phone || channels.whatsapp);
  const tag = document.querySelector(".hero-copy .eyebrow");
  if (tag) tag.textContent = content.hero.tag;
  const folio = document.querySelector(".hero-image .folio");
  if (folio) folio.textContent = "01 / " + content.brand.toLocaleUpperCase("pt-BR");

  const form = document.querySelector("[data-contact]");
  if (!form) return;
  const initialArea = new URLSearchParams(location.search).get("area");
  if (content.areas.some((_, index) => String(index + 1) === initialArea)) {
    form.elements.area.value = initialArea;
  }
  const status = form.querySelector(".form-status");
  const submit = form.querySelector("button[type=submit]");
  const staticPreview = form.hasAttribute("data-static");
  if (staticPreview) submit.disabled = false;
  const showStatus = (message) => {
    status.hidden = false;
    status.textContent = message;
  };
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (staticPreview || content.demo || location.protocol === "file:") {
      showStatus("Esta é uma prévia. Nenhum dado foi enviado.");
      return;
    }
    submit.disabled = true;
    showStatus("Enviando sua solicitação…");
    try {
      const response = await fetch(form.action, {
        method: "POST",
        headers: { Accept: "application/json" },
        body: new FormData(form)
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        showStatus(result.message || "O envio está indisponível. Use o e-mail do escritório.");
        return;
      }
      form.reset();
      showStatus(result.message);
    } catch {
      showStatus("O envio está indisponível. Use o e-mail do escritório.");
    } finally {
      submit.disabled = false;
    }
  });
})();
