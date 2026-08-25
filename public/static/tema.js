/* Minhas Contas — tema claro/escuro
   =================================
   Arquivo separado e carregado no <head>, ANTES do resto: ele escreve
   data-tema no <html> antes do primeiro paint, e é isso que evita o
   piscar de tela escura em quem usa o tema claro.

   Não dá para fazer isso com <script> inline: a CSP da aplicação é
   "script-src 'self'", que recusa script embutido no HTML.

   A regra é uma só: enquanto a pessoa não escolher, o app segue o sistema
   operacional — e continua seguindo, ao vivo, se o SO trocar de tema no meio
   do uso (o macOS e o Windows fazem isso sozinhos ao anoitecer). Escolheu,
   a escolha manda. */
"use strict";

(() => {
  const CHAVE = "tema";
  const raiz = document.documentElement;
  const consultaSO = matchMedia("(prefers-color-scheme: light)");

  const temaDoSO = () => (consultaSO.matches ? "claro" : "escuro");

  /* localStorage estoura em navegação privada de alguns navegadores e quando
     o site está com cookies bloqueados. Tema é conforto, não pode derrubar o
     app: no erro, vale o que o sistema disser. */
  const escolhido = () => {
    try {
      const v = localStorage.getItem(CHAVE);
      return v === "claro" || v === "escuro" ? v : null;
    } catch {
      return null;
    }
  };

  const guardar = (tema) => {
    try {
      if (tema === null) localStorage.removeItem(CHAVE);
      else localStorage.setItem(CHAVE, tema);
    } catch {
      /* sem persistência, o tema vale só nesta aba */
    }
  };

  const aplicar = (tema) => {
    raiz.dataset.tema = tema;
    // Avisa o navegador para desenhar barra de rolagem, seletor de data e
    // demais controles nativos no tom certo — sem isto o <input type="date">
    // abre um calendário escuro no meio do tema claro.
    raiz.style.colorScheme = tema === "claro" ? "light" : "dark";
  };

  aplicar(escolhido() ?? temaDoSO());

  consultaSO.addEventListener("change", () => {
    if (escolhido() !== null) return;   // escolha explícita ganha do SO
    aplicar(temaDoSO());
    window.dispatchEvent(new CustomEvent("temamudou"));
  });

  window.Tema = {
    atual: () => raiz.dataset.tema,

    /** Está seguindo o sistema, ou a pessoa já escolheu? */
    segueSistema: () => escolhido() === null,

    alternar() {
      const novo = raiz.dataset.tema === "claro" ? "escuro" : "claro";
      // Voltar para o tema que o sistema já usa apaga a preferência em vez de
      // gravá-la: assim o app volta a acompanhar o SO daí em diante. É o que
      // dá um caminho de volta para o automático sem precisar de um terceiro
      // estado no botão.
      guardar(novo === temaDoSO() ? null : novo);
      aplicar(novo);
      window.dispatchEvent(new CustomEvent("temamudou"));
      return novo;
    },
  };
})();
