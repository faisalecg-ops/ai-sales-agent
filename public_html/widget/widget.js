/*!
 * AI Sales Agent Platform — embeddable widget
 *
 * Embed with a single tag on the exhibition website:
 *   <script src="https://salesagent.example.net/widget/widget.js" data-agent="buildasia"></script>
 *
 * Notes on the two things that matter most here:
 *
 * 1. Nothing the model produces is ever assigned to innerHTML. The API
 *    returns a structured block list and this file builds DOM nodes,
 *    setting text through textContent only.
 * 2. The widget carries no secret of any kind. The agent slug is a public
 *    lookup key; every decision is made server-side.
 */
(function () {
  'use strict';

  // Bumped whenever this file changes, so the diagnostic page can report
  // which copy is actually live and rule out a stale cached version.
  var WIDGET_VERSION = '1.2.0';

  if (window.__aisaWidgetLoaded) { return; }
  window.__aisaWidgetLoaded = true;
  window.__aisaWidgetVersion = WIDGET_VERSION;

  // ---------------------------------------------------------------------
  // Configuration
  // ---------------------------------------------------------------------
  var script = document.currentScript || (function () {
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (all[i].src && all[i].src.indexOf('widget.js') !== -1) { return all[i]; }
    }
    return null;
  })();

  if (!script) {
    if (window.console) {
      console.warn('[AI Sales Agent] Could not identify the embed tag; widget not loaded.');
    }
    return;
  }

  var AGENT = script.getAttribute('data-agent') || '';
  if (!AGENT) {
    if (window.console) { console.warn('[AI Sales Agent] data-agent is missing from the embed tag.'); }
    return;
  }

  var BASE = (function () {
    var src = script.src;
    var idx = src.lastIndexOf('/widget/');
    return idx === -1 ? src.replace(/\/[^/]*$/, '') : src.substring(0, idx);
  })();

  var POSITION = (script.getAttribute('data-position') || 'right').toLowerCase();
  var LAUNCH_LABEL = script.getAttribute('data-label') || 'Chat with us';
  var AUTO_OPEN = script.getAttribute('data-auto-open') === 'true';

  var STORAGE_VISITOR = 'aisa_visitor_id';
  var STORAGE_SESSION = 'aisa_session_' + AGENT;

  // ---------------------------------------------------------------------
  // Small helpers
  // ---------------------------------------------------------------------
  function randomId(bytes) {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var out = '';
    var buf;
    if (window.crypto && window.crypto.getRandomValues) {
      buf = new Uint8Array(bytes);
      window.crypto.getRandomValues(buf);
      for (var i = 0; i < bytes; i++) { out += chars[buf[i] % chars.length]; }
    } else {
      for (var j = 0; j < bytes; j++) {
        out += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    return out;
  }

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }
  function sessionGet(key) {
    try { return window.sessionStorage.getItem(key); } catch (e) { return null; }
  }
  function sessionSet(key, value) {
    try { window.sessionStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }

  // ---------------------------------------------------------------------
  // Languages (owner, 13 Nov 2026)
  //
  // The visitor chooses a language on the first card; English by default.
  // Everything the window itself says is shown in that language, and the
  // choice goes to the server with every message so the assistant replies in
  // it. Urdu is Roman Urdu (Urdu in Latin letters), as the owner asked.
  // ---------------------------------------------------------------------
  var STORAGE_LANG = 'aisa_lang';
  var LANGS = [
    ['en', 'English'], ['zh', '中文 (Chinese)'], ['ur', 'Urdu (Roman)'], ['ar', 'العربية (Arabic)'],
    ['tr', 'Türkçe (Turkish)'], ['ru', 'Русский (Russian)'], ['es', 'Español (Spanish)'], ['fr', 'Français (French)'],
    ['de', 'Deutsch (German)'], ['ko', '한국어 (Korean)'], ['it', 'Italiano (Italian)'], ['ja', '日本語 (Japanese)'],
    ['fa', 'فارسی (Persian)'], ['th', 'ไทย (Thai)']
  ];
  var RTL = { ar: true, fa: true };
  var TEXT = {
    en: {
      lead: "So we can help you properly, tell us a little about yourself.", language: "Language",
      first: "First name", company: "Company", website: "Company website (optional)", country: "Country your company is based in",
      choose: "Choose a country", interest: "I am interested in", exhibit: "Exhibiting", visit: "Visiting", cont: "Continue",
      skip: "Skip — just ask a question", wait: "One moment",
      e_name: "Please tell us your first name.", e_interest: "Please choose exhibiting or visiting.",
      e_company: "To help with exhibiting, we need your company name.",
      e_country: "To help with exhibiting, choose the country your company is based in.",
      e_timeout: "This chat timed out. Reload the page to start again.",
      e_generic: "That did not go through. Check your connection and try again.",
      said_name: "My name is {name}.", said_from_country: "I am from {org}, based in {country}.", said_from: "I am from {org}.",
      said_country: "I am based in {country}.", said_exhibit: "I am interested in exhibiting.", said_visit: "I am interested in visiting.",
      placeholder: "Type your message", send: "Send", person: "Talk to a person", still: "Still working on your answer…",
      busy: "Sorry — we are very busy right now. ", retry: "Try again", retrying: "Trying again…",
      many: "Many people are asking right now — your answer is on its way…",
      n_fast: "You are sending messages faster than we can process. Wait a moment and try again.",
      n_long: "That message is too long. Shorten it and send again.", n_fail: "That did not send. Try again in a moment.",
      n_conn: "That did not send. Check your connection and try again.",
      f_name: "Your name", f_company: "Company", f_email: "Email address", f_phone: "Phone number", f_note: "Anything we should know",
      f_send: "Send my details", f_sending: "Sending", f_need: "Enter an email address or a phone number so the team can reach you.",
      f_thanks: "Thank you. A member of the team will be in touch.", not_you: "Not you? Start a new chat"
    },
    zh: {
      lead: "为了更好地为您服务，请简单介绍一下您自己。", language: "语言",
      first: "名字", company: "公司", website: "公司网站（选填）", country: "公司所在国家",
      choose: "选择国家", interest: "我感兴趣的是", exhibit: "参展", visit: "参观", cont: "继续",
      skip: "跳过 — 直接提问", wait: "请稍候",
      e_name: "请填写您的名字。", e_interest: "请选择参展或参观。", e_company: "如需参展咨询，请填写公司名称。",
      e_country: "如需参展咨询，请选择公司所在国家。", e_timeout: "会话已超时，请刷新页面重新开始。", e_generic: "提交失败，请检查网络后重试。",
      said_name: "我叫{name}。", said_from_country: "我来自{org}，公司位于{country}。", said_from: "我来自{org}。",
      said_country: "我在{country}。", said_exhibit: "我有意参展。", said_visit: "我有意参观。",
      placeholder: "请输入您的消息", send: "发送", person: "联系人工客服", still: "正在为您准备回答…",
      busy: "抱歉，目前咨询较多。", retry: "重试", retrying: "正在重试…", many: "目前咨询人数较多，回答马上就到…",
      n_fast: "您发送消息过快，请稍等片刻再试。", n_long: "消息过长，请缩短后重新发送。", n_fail: "发送失败，请稍后再试。",
      n_conn: "发送失败，请检查网络后重试。",
      f_name: "您的姓名", f_company: "公司", f_email: "电子邮箱", f_phone: "电话号码", f_note: "其他需要说明的事项",
      f_send: "提交我的信息", f_sending: "正在提交", f_need: "请填写电子邮箱或电话号码，以便团队与您联系。",
      f_thanks: "谢谢！我们的团队会尽快与您联系。", not_you: "不是您本人？开始新的对话"
    },
    ur: {
      lead: "Behtar madad ke liye apne baare mein thora sa bata dein.", language: "Zabaan",
      first: "Pehla naam", company: "Company", website: "Company ki website (zaroori nahi)", country: "Aap ki company kis mulk mein hai",
      choose: "Mulk chunein", interest: "Mujhe dilchaspi hai", exhibit: "Exhibit karne mein", visit: "Visit karne mein", cont: "Aage barhein",
      skip: "Chhorein — seedha sawal poochein", wait: "Ek lamha",
      e_name: "Baraye meharbani apna pehla naam likhein.", e_interest: "Exhibit ya visit mein se ek chunein.",
      e_company: "Exhibit ke liye company ka naam zaroori hai.", e_country: "Exhibit ke liye company ka mulk chunein.",
      e_timeout: "Chat ka waqt khatam ho gaya. Page dobara load karein.", e_generic: "Yeh nahi gaya. Internet check kar ke dobara koshish karein.",
      said_name: "Mera naam {name} hai.", said_from_country: "Main {org} se hoon, hamari company {country} mein hai.", said_from: "Main {org} se hoon.",
      said_country: "Main {country} mein hoon.", said_exhibit: "Mujhe exhibit karne mein dilchaspi hai.", said_visit: "Mujhe visit karne mein dilchaspi hai.",
      placeholder: "Apna message likhein", send: "Bhejein", person: "Kisi insaan se baat karein", still: "Aap ka jawab tayyar ho raha hai…",
      busy: "Maazrat — is waqt bohat log pooch rahe hain. ", retry: "Dobara koshish", retrying: "Dobara koshish ho rahi hai…",
      many: "Is waqt bohat log pooch rahe hain — jawab aa raha hai…",
      n_fast: "Aap bohat tezi se message bhej rahe hain. Thora ruk kar dobara koshish karein.",
      n_long: "Message bohat lamba hai. Chhota kar ke dobara bhejein.", n_fail: "Message nahi gaya. Thori dair baad koshish karein.",
      n_conn: "Message nahi gaya. Internet check kar ke dobara koshish karein.",
      f_name: "Aap ka naam", f_company: "Company", f_email: "Email address", f_phone: "Phone number", f_note: "Koi aur baat jo hamein maloom honi chahiye",
      f_send: "Meri details bhejein", f_sending: "Bheja ja raha hai", f_need: "Email ya phone number likhein taake team aap se raabta kar sake.",
      f_thanks: "Shukriya. Hamari team jald aap se raabta karegi.", not_you: "Aap nahin? Nayi chat shuru karein"
    },
    ar: {
      lead: "حتى نساعدك بشكل أفضل، أخبرنا قليلاً عن نفسك.", language: "اللغة",
      first: "الاسم الأول", company: "الشركة", website: "موقع الشركة (اختياري)", country: "البلد الذي يقع فيه مقر شركتك",
      choose: "اختر البلد", interest: "أنا مهتم بـ", exhibit: "المشاركة كعارض", visit: "الزيارة", cont: "متابعة",
      skip: "تخطَّ — اطرح سؤالك مباشرة", wait: "لحظة من فضلك",
      e_name: "يرجى كتابة اسمك الأول.", e_interest: "يرجى اختيار المشاركة كعارض أو الزيارة.",
      e_company: "للمساعدة في المشاركة كعارض، نحتاج إلى اسم شركتك.", e_country: "للمساعدة في المشاركة كعارض، اختر البلد الذي يقع فيه مقر شركتك.",
      e_timeout: "انتهت مهلة المحادثة. أعد تحميل الصفحة للبدء من جديد.", e_generic: "لم يتم الإرسال. تحقق من الاتصال وحاول مرة أخرى.",
      said_name: "اسمي {name}.", said_from_country: "أنا من {org}، ومقرها في {country}.", said_from: "أنا من {org}.",
      said_country: "أنا في {country}.", said_exhibit: "أنا مهتم بالمشاركة كعارض.", said_visit: "أنا مهتم بالزيارة.",
      placeholder: "اكتب رسالتك", send: "إرسال", person: "التحدث مع شخص", still: "ما زلنا نعدّ إجابتك…",
      busy: "عذراً — هناك ضغط كبير حالياً. ", retry: "حاول مرة أخرى", retrying: "جارٍ المحاولة مرة أخرى…",
      many: "هناك الكثير من الأسئلة الآن — إجابتك في الطريق…",
      n_fast: "أنت ترسل الرسائل بسرعة كبيرة. انتظر لحظة ثم حاول مرة أخرى.", n_long: "الرسالة طويلة جداً. اختصرها وأرسلها مرة أخرى.",
      n_fail: "لم يتم الإرسال. حاول بعد لحظات.", n_conn: "لم يتم الإرسال. تحقق من الاتصال وحاول مرة أخرى.",
      f_name: "اسمك", f_company: "الشركة", f_email: "البريد الإلكتروني", f_phone: "رقم الهاتف", f_note: "أي شيء يجب أن نعرفه",
      f_send: "أرسل بياناتي", f_sending: "جارٍ الإرسال", f_need: "أدخل بريداً إلكترونياً أو رقم هاتف حتى يتمكن الفريق من التواصل معك.",
      f_thanks: "شكراً لك. سيتواصل معك أحد أعضاء الفريق.", not_you: "لست أنت؟ ابدأ محادثة جديدة"
    },
    tr: {
      lead: "Size daha iyi yardımcı olabilmemiz için kendinizden biraz bahsedin.", language: "Dil",
      first: "Adınız", company: "Firma", website: "Firma web sitesi (isteğe bağlı)", country: "Firmanızın bulunduğu ülke",
      choose: "Ülke seçin", interest: "İlgilendiğim konu", exhibit: "Katılımcı olmak", visit: "Ziyaret etmek", cont: "Devam",
      skip: "Atla — doğrudan soru sor", wait: "Bir saniye",
      e_name: "Lütfen adınızı yazın.", e_interest: "Lütfen katılımcı olmayı veya ziyareti seçin.",
      e_company: "Katılım konusunda yardımcı olabilmemiz için firma adınız gerekli.", e_country: "Katılım konusunda yardımcı olabilmemiz için firmanızın ülkesini seçin.",
      e_timeout: "Sohbetin süresi doldu. Yeniden başlamak için sayfayı yenileyin.", e_generic: "Gönderilemedi. Bağlantınızı kontrol edip tekrar deneyin.",
      said_name: "Adım {name}.", said_from_country: "{org} firmasındanım, merkezimiz {country}.", said_from: "{org} firmasındanım.",
      said_country: "{country} merkezliyim.", said_exhibit: "Katılımcı olarak katılmakla ilgileniyorum.", said_visit: "Ziyaret etmekle ilgileniyorum.",
      placeholder: "Mesajınızı yazın", send: "Gönder", person: "Bir kişiyle görüşün", still: "Yanıtınız hazırlanıyor…",
      busy: "Üzgünüz — şu anda yoğunluk var. ", retry: "Tekrar dene", retrying: "Tekrar deneniyor…", many: "Şu anda çok soru var — yanıtınız yolda…",
      n_fast: "Mesajları çok hızlı gönderiyorsunuz. Biraz bekleyip tekrar deneyin.", n_long: "Mesaj çok uzun. Kısaltıp tekrar gönderin.",
      n_fail: "Gönderilemedi. Biraz sonra tekrar deneyin.", n_conn: "Gönderilemedi. Bağlantınızı kontrol edip tekrar deneyin.",
      f_name: "Adınız", f_company: "Firma", f_email: "E-posta adresi", f_phone: "Telefon numarası", f_note: "Bilmemiz gereken başka bir şey",
      f_send: "Bilgilerimi gönder", f_sending: "Gönderiliyor", f_need: "Ekibin size ulaşabilmesi için bir e-posta adresi veya telefon numarası girin.",
      f_thanks: "Teşekkürler. Ekibimizden biri sizinle iletişime geçecek.", not_you: "Siz değil misiniz? Yeni sohbet başlatın"
    },
    ru: {
      lead: "Чтобы мы могли вам помочь, расскажите немного о себе.", language: "Язык",
      first: "Имя", company: "Компания", website: "Сайт компании (необязательно)", country: "Страна, где находится ваша компания",
      choose: "Выберите страну", interest: "Меня интересует", exhibit: "Участие в выставке", visit: "Посещение", cont: "Продолжить",
      skip: "Пропустить — задать вопрос", wait: "Минутку",
      e_name: "Пожалуйста, укажите ваше имя.", e_interest: "Выберите участие или посещение.",
      e_company: "Для участия нам нужно название вашей компании.", e_country: "Для участия выберите страну, где находится ваша компания.",
      e_timeout: "Время сеанса истекло. Обновите страницу, чтобы начать заново.", e_generic: "Не удалось отправить. Проверьте подключение и попробуйте снова.",
      said_name: "Меня зовут {name}.", said_from_country: "Я из компании {org}, она находится в стране {country}.", said_from: "Я из компании {org}.",
      said_country: "Я нахожусь в стране {country}.", said_exhibit: "Меня интересует участие в качестве экспонента.", said_visit: "Меня интересует посещение выставки.",
      placeholder: "Введите сообщение", send: "Отправить", person: "Связаться с человеком", still: "Готовим ваш ответ…",
      busy: "Извините — сейчас очень много обращений. ", retry: "Повторить", retrying: "Повторяем…", many: "Сейчас много вопросов — ответ уже готовится…",
      n_fast: "Вы отправляете сообщения слишком быстро. Подождите немного и попробуйте снова.", n_long: "Сообщение слишком длинное. Сократите его и отправьте снова.",
      n_fail: "Не удалось отправить. Попробуйте чуть позже.", n_conn: "Не удалось отправить. Проверьте подключение и попробуйте снова.",
      f_name: "Ваше имя", f_company: "Компания", f_email: "Эл. почта", f_phone: "Номер телефона", f_note: "Что нам ещё стоит знать",
      f_send: "Отправить мои данные", f_sending: "Отправка", f_need: "Укажите эл. почту или телефон, чтобы команда могла с вами связаться.",
      f_thanks: "Спасибо. Наш сотрудник свяжется с вами.", not_you: "Это не вы? Начните новый чат"
    },
    es: {
      lead: "Para ayudarle mejor, cuéntenos un poco sobre usted.", language: "Idioma",
      first: "Nombre", company: "Empresa", website: "Sitio web de la empresa (opcional)", country: "País donde está su empresa",
      choose: "Elija un país", interest: "Me interesa", exhibit: "Exponer", visit: "Visitar", cont: "Continuar",
      skip: "Omitir — hacer una pregunta", wait: "Un momento",
      e_name: "Indique su nombre, por favor.", e_interest: "Elija exponer o visitar.",
      e_company: "Para ayudarle a exponer necesitamos el nombre de su empresa.", e_country: "Para ayudarle a exponer, elija el país de su empresa.",
      e_timeout: "El chat ha caducado. Recargue la página para empezar de nuevo.", e_generic: "No se pudo enviar. Revise su conexión e inténtelo de nuevo.",
      said_name: "Me llamo {name}.", said_from_country: "Soy de {org}, con sede en {country}.", said_from: "Soy de {org}.",
      said_country: "Estoy en {country}.", said_exhibit: "Me interesa exponer.", said_visit: "Me interesa visitar.",
      placeholder: "Escriba su mensaje", send: "Enviar", person: "Hablar con una persona", still: "Seguimos preparando su respuesta…",
      busy: "Lo sentimos — ahora mismo hay mucha demanda. ", retry: "Reintentar", retrying: "Reintentando…", many: "Hay muchas consultas ahora — su respuesta está en camino…",
      n_fast: "Está enviando mensajes demasiado rápido. Espere un momento e inténtelo de nuevo.", n_long: "El mensaje es demasiado largo. Acórtelo y envíelo de nuevo.",
      n_fail: "No se pudo enviar. Inténtelo en un momento.", n_conn: "No se pudo enviar. Revise su conexión e inténtelo de nuevo.",
      f_name: "Su nombre", f_company: "Empresa", f_email: "Correo electrónico", f_phone: "Teléfono", f_note: "Algo más que debamos saber",
      f_send: "Enviar mis datos", f_sending: "Enviando", f_need: "Indique un correo o un teléfono para que el equipo pueda contactarle.",
      f_thanks: "Gracias. Un miembro del equipo se pondrá en contacto.", not_you: "¿No es usted? Empiece un chat nuevo"
    },
    fr: {
      lead: "Pour bien vous aider, présentez-vous en quelques mots.", language: "Langue",
      first: "Prénom", company: "Entreprise", website: "Site web de l'entreprise (facultatif)", country: "Pays où se trouve votre entreprise",
      choose: "Choisissez un pays", interest: "Je suis intéressé par", exhibit: "Exposer", visit: "Visiter", cont: "Continuer",
      skip: "Passer — poser une question", wait: "Un instant",
      e_name: "Veuillez indiquer votre prénom.", e_interest: "Choisissez exposer ou visiter.",
      e_company: "Pour vous aider à exposer, il nous faut le nom de votre entreprise.", e_country: "Pour vous aider à exposer, choisissez le pays de votre entreprise.",
      e_timeout: "La conversation a expiré. Rechargez la page pour recommencer.", e_generic: "L'envoi a échoué. Vérifiez votre connexion et réessayez.",
      said_name: "Je m'appelle {name}.", said_from_country: "Je travaille chez {org}, basée en {country}.", said_from: "Je travaille chez {org}.",
      said_country: "Je suis basé en {country}.", said_exhibit: "Je souhaite exposer.", said_visit: "Je souhaite visiter.",
      placeholder: "Écrivez votre message", send: "Envoyer", person: "Parler à une personne", still: "Nous préparons votre réponse…",
      busy: "Désolé — nous sommes très sollicités en ce moment. ", retry: "Réessayer", retrying: "Nouvelle tentative…", many: "Beaucoup de questions en ce moment — votre réponse arrive…",
      n_fast: "Vous envoyez des messages trop vite. Patientez un instant puis réessayez.", n_long: "Le message est trop long. Raccourcissez-le et renvoyez-le.",
      n_fail: "L'envoi a échoué. Réessayez dans un instant.", n_conn: "L'envoi a échoué. Vérifiez votre connexion et réessayez.",
      f_name: "Votre nom", f_company: "Entreprise", f_email: "Adresse e-mail", f_phone: "Téléphone", f_note: "Autre chose à nous dire",
      f_send: "Envoyer mes coordonnées", f_sending: "Envoi", f_need: "Indiquez un e-mail ou un téléphone pour que l'équipe puisse vous joindre.",
      f_thanks: "Merci. Un membre de l'équipe vous contactera.", not_you: "Ce n'est pas vous ? Nouvelle conversation"
    },
    de: {
      lead: "Damit wir Ihnen gut helfen können, erzählen Sie uns kurz etwas über sich.", language: "Sprache",
      first: "Vorname", company: "Firma", website: "Firmenwebsite (optional)", country: "Land, in dem Ihre Firma sitzt",
      choose: "Land wählen", interest: "Ich interessiere mich für", exhibit: "Ausstellen", visit: "Besuchen", cont: "Weiter",
      skip: "Überspringen — direkt fragen", wait: "Einen Moment",
      e_name: "Bitte geben Sie Ihren Vornamen an.", e_interest: "Bitte wählen Sie Ausstellen oder Besuchen.",
      e_company: "Für eine Ausstellerberatung brauchen wir Ihren Firmennamen.", e_country: "Für eine Ausstellerberatung wählen Sie bitte das Land Ihrer Firma.",
      e_timeout: "Der Chat ist abgelaufen. Laden Sie die Seite neu, um neu zu beginnen.", e_generic: "Das hat nicht geklappt. Prüfen Sie Ihre Verbindung und versuchen Sie es erneut.",
      said_name: "Mein Name ist {name}.", said_from_country: "Ich bin von {org} mit Sitz in {country}.", said_from: "Ich bin von {org}.",
      said_country: "Ich bin in {country}.", said_exhibit: "Ich interessiere mich für eine Teilnahme als Aussteller.", said_visit: "Ich interessiere mich für einen Besuch.",
      placeholder: "Ihre Nachricht", send: "Senden", person: "Mit einem Menschen sprechen", still: "Ihre Antwort wird vorbereitet…",
      busy: "Entschuldigung — gerade ist sehr viel los. ", retry: "Erneut versuchen", retrying: "Neuer Versuch…", many: "Gerade fragen viele — Ihre Antwort ist unterwegs…",
      n_fast: "Sie senden Nachrichten zu schnell. Warten Sie kurz und versuchen Sie es erneut.", n_long: "Die Nachricht ist zu lang. Kürzen Sie sie und senden Sie sie erneut.",
      n_fail: "Senden fehlgeschlagen. Versuchen Sie es gleich noch einmal.", n_conn: "Senden fehlgeschlagen. Prüfen Sie Ihre Verbindung und versuchen Sie es erneut.",
      f_name: "Ihr Name", f_company: "Firma", f_email: "E-Mail-Adresse", f_phone: "Telefonnummer", f_note: "Was wir noch wissen sollten",
      f_send: "Meine Angaben senden", f_sending: "Wird gesendet", f_need: "Geben Sie eine E-Mail-Adresse oder Telefonnummer an, damit das Team Sie erreichen kann.",
      f_thanks: "Danke. Jemand aus dem Team meldet sich bei Ihnen.", not_you: "Nicht Sie? Neuen Chat beginnen"
    },
    ko: {
      lead: "더 잘 도와드릴 수 있도록 간단히 소개해 주세요.", language: "언어",
      first: "이름", company: "회사", website: "회사 웹사이트 (선택)", country: "회사 소재 국가",
      choose: "국가 선택", interest: "관심 분야", exhibit: "전시 참가", visit: "방문", cont: "계속",
      skip: "건너뛰기 — 바로 질문하기", wait: "잠시만요",
      e_name: "이름을 입력해 주세요.", e_interest: "전시 참가 또는 방문을 선택해 주세요.",
      e_company: "전시 참가 상담을 위해 회사명이 필요합니다.", e_country: "전시 참가 상담을 위해 회사 소재 국가를 선택해 주세요.",
      e_timeout: "채팅 시간이 만료되었습니다. 페이지를 새로 고쳐 다시 시작하세요.", e_generic: "전송되지 않았습니다. 연결을 확인하고 다시 시도하세요.",
      said_name: "제 이름은 {name}입니다.", said_from_country: "저는 {country}에 있는 {org} 소속입니다.", said_from: "저는 {org} 소속입니다.",
      said_country: "저는 {country}에 있습니다.", said_exhibit: "전시 참가에 관심이 있습니다.", said_visit: "방문에 관심이 있습니다.",
      placeholder: "메시지를 입력하세요", send: "보내기", person: "상담원과 대화", still: "답변을 준비하고 있습니다…",
      busy: "죄송합니다 — 지금 문의가 많습니다. ", retry: "다시 시도", retrying: "다시 시도하는 중…", many: "지금 문의가 많습니다 — 곧 답변드리겠습니다…",
      n_fast: "메시지를 너무 빨리 보내고 있습니다. 잠시 후 다시 시도하세요.", n_long: "메시지가 너무 깁니다. 줄여서 다시 보내 주세요.",
      n_fail: "전송되지 않았습니다. 잠시 후 다시 시도하세요.", n_conn: "전송되지 않았습니다. 연결을 확인하고 다시 시도하세요.",
      f_name: "성함", f_company: "회사", f_email: "이메일 주소", f_phone: "전화번호", f_note: "알려 주실 내용",
      f_send: "내 정보 보내기", f_sending: "보내는 중", f_need: "팀이 연락드릴 수 있도록 이메일 또는 전화번호를 입력해 주세요.",
      f_thanks: "감사합니다. 담당자가 곧 연락드리겠습니다.", not_you: "본인이 아니신가요? 새 채팅 시작"
    },
    it: {
      lead: "Per aiutarla al meglio, ci dica qualcosa di lei.", language: "Lingua",
      first: "Nome", company: "Azienda", website: "Sito web dell'azienda (facoltativo)", country: "Paese in cui ha sede la sua azienda",
      choose: "Scelga un paese", interest: "Sono interessato a", exhibit: "Esporre", visit: "Visitare", cont: "Continua",
      skip: "Salta — fai una domanda", wait: "Un momento",
      e_name: "Indichi il suo nome, per favore.", e_interest: "Scelga esporre o visitare.",
      e_company: "Per aiutarla a esporre ci serve il nome della sua azienda.", e_country: "Per aiutarla a esporre, scelga il paese della sua azienda.",
      e_timeout: "La chat è scaduta. Ricarichi la pagina per ricominciare.", e_generic: "Invio non riuscito. Controlli la connessione e riprovi.",
      said_name: "Mi chiamo {name}.", said_from_country: "Lavoro per {org}, con sede in {country}.", said_from: "Lavoro per {org}.",
      said_country: "Mi trovo in {country}.", said_exhibit: "Sono interessato a esporre.", said_visit: "Sono interessato a visitare.",
      placeholder: "Scriva il suo messaggio", send: "Invia", person: "Parlare con una persona", still: "Stiamo preparando la risposta…",
      busy: "Ci scusi — in questo momento siamo molto impegnati. ", retry: "Riprova", retrying: "Nuovo tentativo…",
      many: "Molte richieste in questo momento — la risposta è in arrivo…",
      n_fast: "Sta inviando messaggi troppo velocemente. Attenda un momento e riprovi.", n_long: "Il messaggio è troppo lungo. Lo accorci e lo invii di nuovo.",
      n_fail: "Invio non riuscito. Riprovi tra un momento.", n_conn: "Invio non riuscito. Controlli la connessione e riprovi.",
      f_name: "Il suo nome", f_company: "Azienda", f_email: "Indirizzo e-mail", f_phone: "Numero di telefono", f_note: "Altro che dovremmo sapere",
      f_send: "Invia i miei dati", f_sending: "Invio in corso", f_need: "Inserisca un'e-mail o un numero di telefono perché il team possa contattarla.",
      f_thanks: "Grazie. Un membro del team la contatterà.", not_you: "Non è lei? Inizi una nuova chat"
    },
    ja: {
      lead: "より適切にご案内するため、簡単にご紹介ください。", language: "言語",
      first: "お名前（名）", company: "会社名", website: "会社のウェブサイト（任意）", country: "会社の所在国",
      choose: "国を選択", interest: "ご関心", exhibit: "出展", visit: "来場", cont: "次へ",
      skip: "スキップして質問する", wait: "少々お待ちください",
      e_name: "お名前をご入力ください。", e_interest: "出展か来場をお選びください。",
      e_company: "出展のご案内には会社名が必要です。", e_country: "出展のご案内のため、会社の所在国をお選びください。",
      e_timeout: "チャットの有効期限が切れました。ページを再読み込みしてください。", e_generic: "送信できませんでした。接続をご確認のうえ、もう一度お試しください。",
      said_name: "{name}と申します。", said_from_country: "{country}の{org}の者です。", said_from: "{org}の者です。",
      said_country: "{country}におります。", said_exhibit: "出展に関心があります。", said_visit: "来場に関心があります。",
      placeholder: "メッセージを入力", send: "送信", person: "担当者と話す", still: "回答を準備しています…",
      busy: "申し訳ございません。ただいま大変混み合っております。", retry: "再試行", retrying: "再試行しています…",
      many: "ただいまお問い合わせが多くなっております。まもなく回答いたします…",
      n_fast: "送信が早すぎます。少し待ってからもう一度お試しください。", n_long: "メッセージが長すぎます。短くしてもう一度送信してください。",
      n_fail: "送信できませんでした。しばらくしてからお試しください。", n_conn: "送信できませんでした。接続をご確認のうえ、もう一度お試しください。",
      f_name: "お名前", f_company: "会社名", f_email: "メールアドレス", f_phone: "電話番号", f_note: "その他お伝えいただきたいこと",
      f_send: "情報を送信", f_sending: "送信中", f_need: "担当者からご連絡できるよう、メールアドレスまたは電話番号をご入力ください。",
      f_thanks: "ありがとうございます。担当者よりご連絡いたします。", not_you: "ご本人ではありませんか？新しいチャットを始める"
    },
    fa: {
      lead: "برای اینکه بهتر کمکتان کنیم، کمی درباره خودتان بگویید.", language: "زبان",
      first: "نام", company: "شرکت", website: "وب‌سایت شرکت (اختیاری)", country: "کشور محل شرکت شما",
      choose: "انتخاب کشور", interest: "علاقه‌مندم به", exhibit: "شرکت به‌عنوان غرفه‌دار", visit: "بازدید", cont: "ادامه",
      skip: "رد شدن — مستقیم بپرسید", wait: "لحظه‌ای صبر کنید",
      e_name: "لطفاً نام خود را بنویسید.", e_interest: "لطفاً غرفه‌داری یا بازدید را انتخاب کنید.",
      e_company: "برای راهنمایی درباره غرفه، نام شرکت لازم است.", e_country: "برای راهنمایی درباره غرفه، کشور محل شرکت را انتخاب کنید.",
      e_timeout: "زمان گفتگو به پایان رسید. برای شروع دوباره صفحه را بارگذاری کنید.", e_generic: "ارسال نشد. اتصال را بررسی کنید و دوباره تلاش کنید.",
      said_name: "نام من {name} است.", said_from_country: "از شرکت {org} هستم که در {country} مستقر است.", said_from: "از شرکت {org} هستم.",
      said_country: "من در {country} هستم.", said_exhibit: "به شرکت در نمایشگاه به‌عنوان غرفه‌دار علاقه دارم.", said_visit: "به بازدید از نمایشگاه علاقه دارم.",
      placeholder: "پیام خود را بنویسید", send: "ارسال", person: "صحبت با یک نفر", still: "در حال آماده کردن پاسخ…",
      busy: "پوزش می‌خواهیم — در حال حاضر درخواست‌ها زیاد است. ", retry: "تلاش دوباره", retrying: "در حال تلاش دوباره…",
      many: "در حال حاضر سؤال‌ها زیاد است — پاسخ شما در راه است…",
      n_fast: "پیام‌ها را خیلی سریع می‌فرستید. کمی صبر کنید و دوباره تلاش کنید.", n_long: "پیام خیلی طولانی است. کوتاه‌ترش کنید و دوباره بفرستید.",
      n_fail: "ارسال نشد. کمی بعد دوباره تلاش کنید.", n_conn: "ارسال نشد. اتصال را بررسی کنید و دوباره تلاش کنید.",
      f_name: "نام شما", f_company: "شرکت", f_email: "ایمیل", f_phone: "شماره تلفن", f_note: "نکته دیگری که باید بدانیم",
      f_send: "ارسال اطلاعات من", f_sending: "در حال ارسال", f_need: "یک ایمیل یا شماره تلفن وارد کنید تا تیم بتواند با شما تماس بگیرد.",
      f_thanks: "سپاسگزاریم. یکی از اعضای تیم با شما تماس خواهد گرفت.", not_you: "شما نیستید؟ گفتگوی جدید را شروع کنید"
    },
    th: {
      lead: "เพื่อให้เราช่วยคุณได้ดีขึ้น กรุณาแนะนำตัวสั้นๆ", language: "ภาษา",
      first: "ชื่อ", company: "บริษัท", website: "เว็บไซต์บริษัท (ไม่บังคับ)", country: "ประเทศที่ตั้งของบริษัท",
      choose: "เลือกประเทศ", interest: "ฉันสนใจ", exhibit: "ออกบูธ", visit: "เข้าชมงาน", cont: "ต่อไป",
      skip: "ข้าม — ถามคำถามเลย", wait: "รอสักครู่",
      e_name: "กรุณากรอกชื่อของคุณ", e_interest: "กรุณาเลือกออกบูธหรือเข้าชมงาน",
      e_company: "สำหรับการออกบูธ เราต้องการชื่อบริษัทของคุณ", e_country: "สำหรับการออกบูธ กรุณาเลือกประเทศที่ตั้งของบริษัท",
      e_timeout: "แชทหมดเวลาแล้ว กรุณาโหลดหน้าใหม่เพื่อเริ่มอีกครั้ง", e_generic: "ส่งไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่อแล้วลองอีกครั้ง",
      said_name: "ฉันชื่อ {name}", said_from_country: "ฉันมาจาก {org} ซึ่งตั้งอยู่ที่ {country}", said_from: "ฉันมาจาก {org}",
      said_country: "ฉันอยู่ที่ {country}", said_exhibit: "ฉันสนใจเข้าร่วมออกบูธ", said_visit: "ฉันสนใจเข้าชมงาน",
      placeholder: "พิมพ์ข้อความของคุณ", send: "ส่ง", person: "คุยกับเจ้าหน้าที่", still: "กำลังเตรียมคำตอบให้คุณ…",
      busy: "ขออภัย — ขณะนี้มีผู้ติดต่อจำนวนมาก ", retry: "ลองอีกครั้ง", retrying: "กำลังลองอีกครั้ง…",
      many: "ขณะนี้มีคำถามจำนวนมาก — คำตอบกำลังมา…",
      n_fast: "คุณส่งข้อความเร็วเกินไป กรุณารอสักครู่แล้วลองอีกครั้ง", n_long: "ข้อความยาวเกินไป กรุณาย่อแล้วส่งใหม่",
      n_fail: "ส่งไม่สำเร็จ กรุณาลองใหม่ในอีกสักครู่", n_conn: "ส่งไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่อแล้วลองอีกครั้ง",
      f_name: "ชื่อของคุณ", f_company: "บริษัท", f_email: "อีเมล", f_phone: "หมายเลขโทรศัพท์", f_note: "ข้อมูลอื่นที่เราควรทราบ",
      f_send: "ส่งข้อมูลของฉัน", f_sending: "กำลังส่ง", f_need: "กรุณากรอกอีเมลหรือหมายเลขโทรศัพท์เพื่อให้ทีมงานติดต่อกลับ",
      f_thanks: "ขอบคุณ ทีมงานจะติดต่อกลับโดยเร็ว", not_you: "ไม่ใช่คุณใช่ไหม? เริ่มแชทใหม่"
    }
  };
  var LANG = 'en';

  function t(key, vars) {
    var table = TEXT[LANG] || TEXT.en;
    var s = Object.prototype.hasOwnProperty.call(table, key) ? table[key] : (TEXT.en[key] || key);
    if (vars) {
      Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); });
    }
    return s;
  }

  // Elements whose words follow the chosen language: [node, key, attribute].
  var BOUND = [];
  function bind(node, key, attr) {
    BOUND.push([node, key, attr || null]);
    if (attr) { node.setAttribute(attr, t(key)); } else { node.textContent = t(key); }
    return node;
  }
  function applyLang() {
    BOUND = BOUND.filter(function (b) { return b[0] && (b[0].isConnected !== false || b[0].nodeType === 3); });
    BOUND.forEach(function (b) {
      if (b[2]) { b[0].setAttribute(b[2], t(b[1])); } else { b[0].textContent = t(b[1]); }
    });
    if (typeof panel !== 'undefined' && panel) { panel.setAttribute('dir', RTL[LANG] ? 'rtl' : 'ltr'); }
  }
  function setLang(code) {
    if (!TEXT[code]) { return; }
    LANG = code;
    storageSet(STORAGE_LANG, code);
    applyLang();
  }
  LANG = TEXT[storageGet(STORAGE_LANG)] ? storageGet(STORAGE_LANG) : 'en';

  /**
   * The visitor identifier is generated here and kept in the host page's own
   * localStorage — first-party, so third-party cookie restrictions do not
   * apply. It is sent explicitly with every call.
   */
  function visitorId() {
    var id = storageGet(STORAGE_VISITOR);
    if (!id || id.length < 16) {
      id = 'v' + randomId(23);
      storageSet(STORAGE_VISITOR, id);
    }
    return id;
  }

  /**
   * Where this visitor came from (added 12 Oct 2026 for the Social Media
   * Agent's results — see docs/handover/2026-10-12-social-attribution.md).
   *
   * Links shared on social media, in exhibitor kits and in emails carry
   * utm_* tags. Those tags are only on the first page the visitor lands on;
   * by the time they open the chat they may be on another page. So when a
   * page is opened with tags, they are kept in the site's own localStorage
   * for 30 days and sent when a chat starts. A newer tagged visit replaces an
   * older one. Nothing is kept for visits without tags (except an outside
   * referrer, kept the same way, which shows "came from facebook.com" etc.).
   * The Event Blog writes the same key in the same shape.
   */
  var STORAGE_ARRIVAL = 'aisa_arrival';
  var ARRIVAL_DAYS = 30;

  function rememberArrival() {
    var tags = {};
    var found = false;
    try {
      var q = new URLSearchParams(location.search);
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'].forEach(function (k) {
        var v = q.get(k);
        if (v) { tags[k] = String(v).slice(0, 150); found = true; }
      });
    } catch (e) { return; }
    var refHost = '';
    try {
      if (document.referrer) {
        var r = new URL(document.referrer);
        if (r.host && r.host !== location.host) { refHost = r.host.slice(0, 190); }
      }
    } catch (e2) { /* no referrer */ }
    if (!found && !refHost) { return; }
    // An outside referrer alone never replaces tags we already hold.
    if (!found && readArrival() && readArrival().utm_source) { return; }
    tags.landing_url = (location.origin + location.pathname).slice(0, 500);
    tags.referrer_host = refHost;
    tags.at = Math.floor(Date.now() / 1000);
    storageSet(STORAGE_ARRIVAL, JSON.stringify(tags));
  }

  function readArrival() {
    var raw = storageGet(STORAGE_ARRIVAL);
    if (!raw) { return null; }
    try {
      var a = JSON.parse(raw);
      if (!a || typeof a !== 'object' || !a.at || (Date.now() / 1000 - a.at) > ARRIVAL_DAYS * 86400) { return null; }
      return a;
    } catch (e) { return null; }
  }

  rememberArrival();

  // ---------------------------------------------------------------------
  // Personal chat link (owner, 13 Nov 2026)
  //
  // "Let's discuss more — click here" in an email or WhatsApp message opens
  // the website with ?aisa_p=<signed token>. The token is taken out of the
  // address bar at once (so it is not shared by copying the address), kept
  // for this tab, and sent when the chat starts; the chat then opens by
  // itself and welcomes the person by name.
  // ---------------------------------------------------------------------
  var STORAGE_PERSONAL = 'aisa_personal_' + AGENT;
  var PERSONAL = null;
  (function () {
    try {
      var q = new URLSearchParams(location.search);
      var tok = q.get('aisa_p');
      if (tok && /^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/.test(tok) && tok.length < 400) {
        PERSONAL = tok;
        sessionSet(STORAGE_PERSONAL, tok);
        q.delete('aisa_p');
        var rest = q.toString();
        if (window.history && window.history.replaceState) {
          window.history.replaceState(window.history.state, '', location.pathname + (rest ? '?' + rest : '') + location.hash);
        }
      }
    } catch (e) { /* old browser: the link simply opens the normal chat */ }
  })();
  // Opened by the link just now: the chat opens by itself. A later page in
  // the same tab keeps the person, but waits for them to open the chat.
  var PERSONAL_FRESH = !!PERSONAL;
  if (!PERSONAL) { PERSONAL = sessionGet(STORAGE_PERSONAL) || null; }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function svg(paths, opts) {
    opts = opts || {};
    var ns = 'http://www.w3.org/2000/svg';
    var node = document.createElementNS(ns, 'svg');
    node.setAttribute('viewBox', opts.viewBox || '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', opts.width || '1.6');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.setAttribute('aria-hidden', 'true');
    node.setAttribute('focusable', 'false');
    for (var i = 0; i < paths.length; i++) {
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', paths[i]);
      node.appendChild(p);
    }
    return node;
  }

  var ICON = {
    chat:    ['M21 11.5a8.4 8.4 0 0 1-9 8.4 9.7 9.7 0 0 1-2.8-.4L3 21l1.6-4.6A8.2 8.2 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z'],
    close:   ['M18 6 6 18', 'm6 6 12 12'],
    send:    ['M22 2 11 13', 'M22 2 15 22l-4-9-9-4 20-7z'],
    expand:  ['M15 3h6v6', 'M9 21H3v-6', 'M21 3l-7 7', 'M3 21l7-7'],
    collapse:['M4 14h6v6', 'M20 10h-6V4', 'M14 10l7-7', 'M3 21l7-7'],
    person:  ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
    check:   ['m20 6-11 11-5-5'],
    alert:   ['M12 9v4', 'M12 17h.01', 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z']
  };

  // ---------------------------------------------------------------------
  // Styles. Scoped inside the shadow root, so the host site cannot reach in
  // and the widget cannot leak out.
  // ---------------------------------------------------------------------
  var CSS = [
    ':host{all:initial}',
    '*,*::before,*::after{box-sizing:border-box}',

    '.root{',
    '--ink:#0F2138;--ink-soft:#4A5C72;--ink-faint:#7A8899;',
    '--paper:#FFFFFF;--wash:#F4F6F9;--line:#DCE3EC;',
    '--accent:#1B4E8F;--accent-ink:#FFFFFF;--accent-wash:#ECF2F9;',
    '--signal:#8A5100;--signal-wash:#FBF3E6;',
    '--danger:#A32318;',
    '--radius:6px;',
    '--font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;',
    'font-family:var(--font);color:var(--ink);',
    'font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased;',
    'position:fixed;z-index:2147483000;bottom:0;',
    '}',
    '.root[data-pos="right"]{right:0}',
    '.root[data-pos="left"]{left:0}',

    // ---- Launcher ----
    '.launcher{position:fixed;bottom:20px;display:inline-flex;align-items:center;gap:9px;',
    'padding:12px 18px 12px 15px;border:0;border-radius:999px;cursor:pointer;',
    'background:var(--accent);color:var(--accent-ink);font-family:inherit;font-size:15px;',
    'font-weight:500;letter-spacing:.005em;box-shadow:0 6px 22px rgba(15,33,56,.22);}',
    '.root[data-pos="right"] .launcher{right:20px}',
    '.root[data-pos="left"] .launcher{left:20px}',
    '.launcher:hover{background:#17427A}',
    '.launcher:focus-visible{outline:3px solid #9DBCE4;outline-offset:2px}',
    '.launcher svg{width:20px;height:20px;flex:0 0 auto}',
    '.launcher[hidden]{display:none}',

    // ---- Panel ----
    '.panel{position:fixed;bottom:20px;width:380px;height:600px;max-height:calc(100vh - 40px);',
    'background:var(--paper);border:1px solid var(--line);border-radius:10px;',
    'box-shadow:0 18px 50px rgba(15,33,56,.20);display:flex;flex-direction:column;overflow:hidden}',
    '.root[data-pos="right"] .panel{right:20px}',
    '.root[data-pos="left"] .panel{left:20px}',
    '.panel[hidden]{display:none}',
    '.panel.is-wide{width:min(720px,calc(100vw - 40px));height:min(820px,calc(100vh - 40px))}',

    // ---- Header ----
    '.head{display:flex;align-items:center;gap:10px;padding:13px 12px 13px 16px;',
    'background:var(--ink);color:#fff;flex:0 0 auto}',
    '.head-title{font-size:15px;font-weight:600;letter-spacing:.005em;margin:0;flex:1 1 auto;',
    'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.head-status{font-size:12px;color:#B7C6D8;font-weight:400;display:block;margin-top:1px}',
    '.icon-btn{appearance:none;background:transparent;border:0;color:#C9D6E5;cursor:pointer;',
    'padding:7px;border-radius:5px;display:inline-flex;line-height:0}',
    '.icon-btn:hover{background:rgba(255,255,255,.12);color:#fff}',
    '.icon-btn:focus-visible{outline:2px solid #9DBCE4;outline-offset:1px}',
    '.icon-btn svg{width:17px;height:17px}',

    // ---- Disclosure strip ----
    '.disclosure{padding:8px 16px;background:var(--wash);border-bottom:1px solid var(--line);',
    'font-size:12px;color:var(--ink-soft);flex:0 0 auto}',
    '.disclosure a{color:var(--accent);text-decoration:underline}',

    // ---- Message log ----
    '.log{flex:1 1 auto;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:14px;',
    'scroll-behavior:smooth;overscroll-behavior:contain}',
    '@media (prefers-reduced-motion:reduce){.log{scroll-behavior:auto}}',

    '.msg{max-width:88%;display:flex;flex-direction:column;gap:7px}',
    '.msg.from-agent{align-self:flex-start}',
    '.msg.from-visitor{align-self:flex-end;align-items:flex-end}',
    '.bubble{padding:10px 13px;border-radius:var(--radius);font-size:14.5px;line-height:1.55;',
    'white-space:normal;word-wrap:break-word;overflow-wrap:anywhere}',
    '.from-agent .bubble{background:var(--wash);border:1px solid var(--line);color:var(--ink);',
    'border-top-left-radius:2px}',
    '.from-visitor .bubble{background:var(--accent);color:var(--accent-ink);border-bottom-right-radius:2px}',
    '.bubble p{margin:0 0 8px}',
    '.bubble p:last-child{margin-bottom:0}',
    '.bubble ul,.bubble ol{margin:6px 0 8px;padding-left:19px}',
    '.bubble li{margin:2px 0}',
    '.bubble a{color:inherit;text-decoration:underline}',
    '.from-agent .bubble a{color:var(--accent)}',
    '.bubble strong{font-weight:600}',

    // ---- Package card: the one place the design raises its voice ----
    '.card{border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:5px;',
    'background:var(--paper);padding:13px 14px;width:100%}',
    '.card + .card{margin-top:9px}',
    '.card-top{display:flex;align-items:baseline;justify-content:space-between;gap:12px}',
    '.card-name{font-size:14.5px;font-weight:600;margin:0;color:var(--ink);line-height:1.3}',
    '.card-price{font-size:16px;font-weight:600;color:var(--ink);white-space:nowrap;',
    'font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}',
    '.card-basis{display:block;font-size:11.5px;font-weight:400;color:var(--ink-faint);text-align:right;margin-top:1px}',
    '.card-meta{margin:3px 0 0;font-size:12.5px;color:var(--ink-soft)}',
    '.card-sums{display:grid;grid-template-columns:1fr auto;gap:3px 14px;margin:11px 0 0;',
    'padding-top:10px;border-top:1px solid var(--line);font-size:12.5px}',
    '.card-sums dt{color:var(--ink-soft);margin:0}',
    '.card-sums dd{margin:0;text-align:right;color:var(--ink);',
    'font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}',
    '.card-sums dt.is-total,.card-sums dd.is-total{font-weight:600;color:var(--ink);padding-top:4px}',
    '.card-list{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:4px}',
    '.card-list li{display:flex;gap:7px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.45}',
    '.card-list svg{width:13px;height:13px;flex:0 0 auto;margin-top:3px;color:var(--accent)}',
    '.card-tax{margin:10px 0 0;padding-top:9px;border-top:1px solid var(--line);',
    'font-size:12px;color:var(--ink-soft)}',
    '.card-tax.is-unspecified{color:var(--signal)}',

    // ---- Quick replies ----
    '.quick{display:flex;flex-wrap:wrap;gap:7px;padding:0 16px 4px}',
    '.quick button{appearance:none;font-family:inherit;font-size:13.5px;cursor:pointer;',
    'padding:7px 13px;border-radius:999px;border:1px solid var(--line);background:var(--paper);',
    'color:var(--accent);font-weight:500}',
    '.quick button:hover{background:var(--accent-wash);border-color:#BCCFE6}',
    '.quick button:focus-visible{outline:2px solid var(--accent);outline-offset:1px}',

    // ---- Typing indicator ----
    '.typing{display:flex;gap:4px;align-items:center;padding:12px 13px;background:var(--wash);',
    'border:1px solid var(--line);border-radius:var(--radius);border-top-left-radius:2px;width:fit-content}',
    '.typing span{width:6px;height:6px;border-radius:50%;background:var(--ink-faint);',
    'animation:blink 1.3s infinite ease-in-out}',
    '.typing span:nth-child(2){animation-delay:.18s}',
    '.typing span:nth-child(3){animation-delay:.36s}',
    '@keyframes blink{0%,80%,100%{opacity:.25}40%{opacity:1}}',
    '@media (prefers-reduced-motion:reduce){.typing span{animation:none;opacity:.55}}',
    '.typing-note{font-size:12px;color:var(--ink-faint);margin:4px 2px 0}',
    '.retry-btn{margin-left:6px;font:inherit;font-size:12.5px;font-weight:600;color:var(--accent);background:none;border:0;padding:0;text-decoration:underline;cursor:pointer}',

    // ---- Notice ----
    '.notice{display:flex;gap:9px;align-items:flex-start;margin:0 16px;padding:11px 12px;',
    'background:var(--signal-wash);border:1px solid #EBDCC2;border-radius:5px;',
    'font-size:13px;color:var(--signal)}',
    '.notice svg{width:15px;height:15px;flex:0 0 auto;margin-top:2px}',

    // ---- Composer ----
    '.foot{flex:0 0 auto;border-top:1px solid var(--line);background:var(--paper);padding:10px 12px 12px}',
    '.row{display:flex;gap:8px;align-items:flex-end}',
    '.input{flex:1 1 auto;font-family:inherit;font-size:14.5px;line-height:1.45;color:var(--ink);',
    'border:1px solid var(--line);border-radius:var(--radius);padding:10px 12px;resize:none;',
    'max-height:120px;min-height:42px;background:var(--paper);width:100%}',
    '.input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-wash)}',
    '.input::placeholder{color:var(--ink-faint)}',
    '.send{appearance:none;border:0;background:var(--accent);color:var(--accent-ink);cursor:pointer;',
    'width:42px;height:42px;border-radius:var(--radius);display:inline-flex;align-items:center;',
    'justify-content:center;flex:0 0 auto}',
    '.send:hover:not(:disabled){background:#17427A}',
    '.send:disabled{background:#B9C6D6;cursor:not-allowed}',
    '.send:focus-visible{outline:3px solid #9DBCE4;outline-offset:1px}',
    '.send svg{width:18px;height:18px}',
    '.foot-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px}',
    '.human{appearance:none;background:none;border:0;cursor:pointer;font-family:inherit;',
    'font-size:12.5px;color:var(--ink-soft);display:inline-flex;align-items:center;gap:5px;padding:2px 0}',
    '.human:hover{color:var(--accent)}',
    '.human:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:3px}',
    '.human svg{width:14px;height:14px}',
    '.counter{font-size:11.5px;color:var(--ink-faint);font-variant-numeric:tabular-nums}',
    '.counter.is-over{color:var(--danger)}',

    // ---- Contact form ----
    '.form{padding:2px 16px 16px;display:flex;flex-direction:column;gap:10px}',
    '.field{display:flex;flex-direction:column;gap:4px}',
    '.field label{font-size:12.5px;font-weight:500;color:var(--ink-soft)}',
    '.field input,.field textarea{font-family:inherit;font-size:14px;color:var(--ink);',
    'border:1px solid var(--line);border-radius:var(--radius);padding:9px 11px;background:var(--paper);width:100%}',
    '.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent);',
    'box-shadow:0 0 0 3px var(--accent-wash)}',
    '.field textarea{resize:vertical;min-height:62px}',
    '.form-error{font-size:12.5px;color:var(--danger)}',
    '.submit{appearance:none;border:0;background:var(--accent);color:var(--accent-ink);cursor:pointer;',
    'font-family:inherit;font-size:14.5px;font-weight:500;padding:11px 16px;border-radius:var(--radius)}',
    '.submit:hover:not(:disabled){background:#17427A}',
    '.submit:disabled{background:#B9C6D6;cursor:not-allowed}',
    '.hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}',

    // ---- Introduction card (first name, company, country, exhibit or visit) ----
    '.intro[hidden]{display:none}',
    '.intro{margin:4px 0 6px;padding:14px;border:1px solid var(--line);border-radius:8px;background:var(--paper)}',
    '.intro-lead{margin:0;font-size:13.5px;color:var(--ink-soft)}',
    '.field select{font-family:inherit;font-size:14px;color:var(--ink);border:1px solid var(--line);',
    'border-radius:var(--radius);padding:9px 11px;background:var(--paper);width:100%}',
    '.field select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-wash)}',
    '.choice{border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}',
    '.choice legend{font-size:12.5px;font-weight:500;color:var(--ink-soft);padding:0;margin-bottom:4px}',
    '.choice-row{display:flex;gap:8px}',
    '.choice label{flex:1;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;',
    'border:1px solid var(--line);border-radius:var(--radius);padding:10px 11px;color:var(--ink)}',
    '.choice label:has(input:checked){border-color:var(--accent);background:var(--accent-wash)}',
    '.choice input{accent-color:var(--accent);margin:0}',
    '.skip{appearance:none;border:0;background:none;cursor:pointer;font-family:inherit;font-size:13px;',
    'color:var(--ink-soft);text-decoration:underline;padding:2px;align-self:center}',
    '.skip:hover{color:var(--accent)}',
    '.skip:focus-visible,.choice label:focus-within{outline:2px solid var(--accent);outline-offset:1px}',

    // ---- Mobile: full screen from the moment it opens ----
    '@media (max-width:560px){',
    '.panel,.panel.is-wide{right:0!important;left:0!important;bottom:0!important;top:0!important;',
    'width:100%!important;height:100%!important;max-height:100%!important;border-radius:0;border:0}',
    '.launcher{bottom:16px}',
    '.root[data-pos="right"] .launcher{right:16px}',
    '.root[data-pos="left"] .launcher{left:16px}',
    '.expand-btn{display:none}',
    '}'
  ].join('');

  // ---------------------------------------------------------------------
  // Widget
  // ---------------------------------------------------------------------
  var W = {
    session: null,
    secret: null,
    open: false,
    wide: false,
    busy: false,
    started: false,
    agentName: '',
    disclosure: '',
    privacyUrl: null,
    maxChars: 1200,
    pendingHuman: false
  };

  var host = document.createElement('div');
  host.setAttribute('data-aisa-widget', AGENT);
  var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : null;

  if (!shadow) {
    // Without shadow DOM the host site's CSS could break the panel, and the
    // isolation guarantee no longer holds. Better to stay out of the page.
    if (window.console) { console.warn('[AI Sales Agent] Shadow DOM unsupported; widget not loaded.'); }
    return;
  }

  var style = document.createElement('style');
  style.textContent = CSS;
  shadow.appendChild(style);

  var root = el('div', 'root');
  root.setAttribute('data-pos', POSITION === 'left' ? 'left' : 'right');
  shadow.appendChild(root);

  // ---- Launcher --------------------------------------------------------
  var launcher = el('button', 'launcher');
  launcher.type = 'button';
  launcher.setAttribute('aria-haspopup', 'dialog');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(svg(ICON.chat));
  launcher.appendChild(el('span', null, LAUNCH_LABEL));
  root.appendChild(launcher);

  // ---- Panel -----------------------------------------------------------
  var panel = el('div', 'panel');
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');
  panel.setAttribute('aria-label', 'Chat with our sales assistant');
  panel.setAttribute('dir', RTL[LANG] ? 'rtl' : 'ltr');
  root.appendChild(panel);

  // Header
  var head = el('div', 'head');
  var headText = el('div', null);
  headText.style.cssText = 'flex:1 1 auto;min-width:0';
  var headTitle = el('p', 'head-title', 'Sales assistant');
  var headStatus = el('span', 'head-status', 'Connecting');
  headText.appendChild(headTitle);
  headText.appendChild(headStatus);

  var expandBtn = el('button', 'icon-btn expand-btn');
  expandBtn.type = 'button';
  expandBtn.title = 'Make the panel larger';
  expandBtn.setAttribute('aria-label', 'Make the panel larger');
  expandBtn.appendChild(svg(ICON.expand));

  var closeBtn = el('button', 'icon-btn');
  closeBtn.type = 'button';
  closeBtn.title = 'Close the chat';
  closeBtn.setAttribute('aria-label', 'Close the chat');
  closeBtn.appendChild(svg(ICON.close));

  head.appendChild(headText);
  head.appendChild(expandBtn);
  head.appendChild(closeBtn);
  panel.appendChild(head);

  // Disclosure
  var disclosure = el('div', 'disclosure');
  panel.appendChild(disclosure);

  // Log
  var log = el('div', 'log');
  log.setAttribute('role', 'log');
  log.setAttribute('aria-live', 'polite');
  log.setAttribute('aria-relevant', 'additions');
  panel.appendChild(log);

  // Quick replies
  var quick = el('div', 'quick');
  quick.hidden = true;
  panel.appendChild(quick);

  // Composer
  var foot = el('div', 'foot');
  var row = el('div', 'row');

  var input = document.createElement('textarea');
  input.className = 'input';
  input.rows = 1;
  bind(input, 'placeholder', 'placeholder');
  input.setAttribute('aria-label', 'Your message');

  var hp = document.createElement('input');
  hp.className = 'hp';
  hp.type = 'text';
  hp.name = 'company_website_hp';
  hp.tabIndex = -1;
  hp.setAttribute('autocomplete', 'off');
  hp.setAttribute('aria-hidden', 'true');

  var sendBtn = el('button', 'send');
  sendBtn.type = 'button';
  bind(sendBtn, 'send', 'title');
  sendBtn.setAttribute('aria-label', 'Send message');
  sendBtn.appendChild(svg(ICON.send));

  row.appendChild(input);
  row.appendChild(sendBtn);

  var footRow = el('div', 'foot-row');
  var humanBtn = el('button', 'human');
  humanBtn.type = 'button';
  humanBtn.appendChild(svg(ICON.person));
  humanBtn.appendChild(bind(el('span', null), 'person'));
  var counter = el('span', 'counter', '');
  footRow.appendChild(humanBtn);
  footRow.appendChild(counter);

  foot.appendChild(row);
  foot.appendChild(hp);
  foot.appendChild(footRow);
  panel.appendChild(foot);

  // The embed instructions say to paste the tag before </body>, but people
  // reasonably put it in <head> instead, and there document.body does not
  // exist yet — appending to it would throw and the widget would never
  // appear, with nothing but a console error to explain why. So mount now if
  // the body is ready and otherwise wait for it.
  (function mount() {
    if (document.body) {
      document.body.appendChild(host);
      return;
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mount, { once: true });
      return;
    }
    // Body missing outside of loading is unexpected; try once more next tick
    // rather than failing silently.
    window.setTimeout(function () {
      if (document.body) {
        document.body.appendChild(host);
      } else if (window.console) {
        console.error('[AI Sales Agent] No <body> to attach to; widget not loaded.');
      }
    }, 0);
  })();

  // ---------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------

  /**
   * Build DOM from the server's block list. Text is only ever written via
   * textContent, so model output cannot become markup.
   */
  function renderBlocks(blocks, into) {
    if (!blocks || !blocks.length) { return; }
    for (var i = 0; i < blocks.length; i++) {
      var b = blocks[i];
      if (b.type === 'p') {
        into.appendChild(renderSpans(b.spans, 'p'));
      } else if (b.type === 'ul' || b.type === 'ol') {
        var list = document.createElement(b.type === 'ol' ? 'ol' : 'ul');
        for (var j = 0; j < (b.items || []).length; j++) {
          list.appendChild(renderSpans(b.items[j], 'li'));
        }
        into.appendChild(list);
      }
    }
  }

  function renderSpans(spans, tag) {
    var node = document.createElement(tag);
    for (var i = 0; i < (spans || []).length; i++) {
      var s = spans[i];
      if (s.t === 'b') {
        node.appendChild(el('strong', null, s.v));
      } else if (s.t === 'a' && typeof s.href === 'string' && /^https:\/\//i.test(s.href)) {
        // The server has already restricted these to approved domains.
        var a = document.createElement('a');
        a.href = s.href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.textContent = s.v;
        node.appendChild(a);
      } else {
        // Preserve single line breaks inside a paragraph.
        var parts = String(s.v).split('\n');
        for (var k = 0; k < parts.length; k++) {
          if (k > 0) { node.appendChild(document.createElement('br')); }
          node.appendChild(document.createTextNode(parts[k]));
        }
      }
    }
    return node;
  }

  function addMessage(from, blocks, cards) {
    var wrap = el('div', 'msg from-' + from);
    var bubble = el('div', 'bubble');
    bubble.setAttribute('dir', 'auto');
    renderBlocks(blocks, bubble);
    wrap.appendChild(bubble);

    if (cards && cards.length) {
      for (var i = 0; i < cards.length; i++) {
        wrap.appendChild(renderCard(cards[i]));
      }
    }
    log.appendChild(wrap);
    scrollDown();
    return wrap;
  }

  function addVisitorText(text) {
    return addMessage('visitor', [{ type: 'p', spans: [{ t: 'text', v: text }] }], null);
  }

  /**
   * Package card. Every figure here came from the server, rendered from an
   * approved database record — the model never supplies a number.
   */
  function renderCard(card) {
    var box = el('div', 'card');

    var top = el('div', 'card-top');
    top.appendChild(el('h4', 'card-name', card.title));

    var lines = card.price_lines || [];
    var headline = lines.length ? lines[lines.length - 1] : null;
    var priceWrap = el('div', 'card-price', headline ? headline.value : card.price_text);
    if (card.price_basis) {
      priceWrap.appendChild(el('span', 'card-basis', card.price_basis));
    }
    top.appendChild(priceWrap);
    box.appendChild(top);

    if (card.meta && card.meta.length) {
      box.appendChild(el('p', 'card-meta', card.meta.join(' \u2013 ')));
    }

    // The full breakdown. Every figure was calculated server-side from an
    // approved record; the panel only lays them out.
    if (lines.length > 1) {
      var table = el('dl', 'card-sums');
      for (var n = 0; n < lines.length; n++) {
        var dt = el('dt', lines[n].emphasis ? 'is-total' : null, lines[n].label);
        var dd = el('dd', lines[n].emphasis ? 'is-total' : null, lines[n].value);
        table.appendChild(dt);
        table.appendChild(dd);
      }
      box.appendChild(table);
    }

    if (card.items && card.items.length) {
      var ul = el('ul', 'card-list');
      for (var i = 0; i < card.items.length; i++) {
        var li = document.createElement('li');
        li.appendChild(svg(ICON.check, { width: '2.2' }));
        li.appendChild(el('span', null, card.items[i]));
        ul.appendChild(li);
      }
      box.appendChild(ul);
    }

    if (card.tax_note) {
      var tax = el('p', 'card-tax', card.tax_note);
      if (/not specified/i.test(card.tax_note)) {
        tax.className = 'card-tax is-unspecified';
      }
      box.appendChild(tax);
    }

    return box;
  }

  var typingNode = null;
  var slowTimer = null;
  // The dots, and — if the answer takes a while because the AI is busy — a
  // short line underneath so the visitor knows nothing has gone wrong.
  // quiet: only the dots, with no "still working" line however long it takes
  // (the owner's rule while an exhibitor's company is being researched).
  function showTyping(note, quiet) {
    hideTyping();
    typingNode = el('div', 'msg from-agent');
    var t = el('div', 'typing');
    t.appendChild(el('span'));
    t.appendChild(el('span'));
    t.appendChild(el('span'));
    t.setAttribute('aria-label', 'Assistant is typing');
    typingNode.appendChild(t);
    var line = el('div', 'typing-note', note || '');
    line.setAttribute('aria-live', 'polite');
    if (!note) { line.hidden = true; }
    typingNode.appendChild(line);
    log.appendChild(typingNode);
    scrollDown();
    if (quiet) { return; }
    slowTimer = window.setTimeout(function () {
      if (line.parentNode && line.hidden) {
        line.textContent = t('still');
        line.hidden = false;
        scrollDown();
      }
    }, 7000);
  }
  function hideTyping() {
    if (slowTimer) { window.clearTimeout(slowTimer); slowTimer = null; }
    if (typingNode && typingNode.parentNode) { typingNode.parentNode.removeChild(typingNode); }
    typingNode = null;
  }

  // Still busy after several tries: a button to try once more, without
  // typing the message again.
  function showRetry(text) {
    var note = el('div', 'notice');
    note.appendChild(svg(ICON.alert));
    note.appendChild(el('span', null, t('busy')));
    var b = el('button', 'retry-btn', t('retry'));
    b.type = 'button';
    b.addEventListener('click', function () {
      if (W.busy) { return; }
      if (note.parentNode) { note.parentNode.removeChild(note); }
      setBusy(true);
      showTyping(t('retrying'));
      sendTurn(text, 1);
    });
    note.appendChild(b);
    log.appendChild(note);
    scrollDown();
  }

  function showNotice(text) {
    var note = el('div', 'notice');
    note.appendChild(svg(ICON.alert));
    note.appendChild(el('span', null, text));
    log.appendChild(note);
    scrollDown();
  }

  function scrollDown() {
    window.requestAnimationFrame(function () { log.scrollTop = log.scrollHeight; });
  }

  function setQuickReplies(items) {
    while (quick.firstChild) { quick.removeChild(quick.firstChild); }
    if (!items || !items.length) { quick.hidden = true; return; }
    for (var i = 0; i < items.length; i++) {
      (function (label) {
        var b = el('button', null, label);
        b.type = 'button';
        b.addEventListener('click', function () {
          quick.hidden = true;
          send(label);
        });
        quick.appendChild(b);
      })(items[i]);
    }
    quick.hidden = false;
  }

  // ---------------------------------------------------------------------
  // Contact form (works with no model call at all)
  // ---------------------------------------------------------------------
  var formNode = null;

  function showContactForm(introText) {
    if (formNode) { return; }
    foot.hidden = true;
    quick.hidden = true;

    if (introText) {
      addMessage('agent', [{ type: 'p', spans: [{ t: 'text', v: introText }] }], null);
    }

    formNode = el('div', 'form');

    var fields = {};
    [
      ['name', t('f_name'), 'text', 'name'],
      ['company', t('f_company'), 'text', 'organization'],
      ['email', t('f_email'), 'email', 'email'],
      ['phone', t('f_phone'), 'tel', 'tel']
    ].forEach(function (f) {
      var wrap = el('div', 'field');
      var id = 'aisa-' + f[0];
      var label = el('label', null, f[1]);
      label.setAttribute('for', id);
      var inp = document.createElement('input');
      inp.type = f[2];
      inp.id = id;
      inp.setAttribute('autocomplete', f[3]);
      wrap.appendChild(label);
      wrap.appendChild(inp);
      formNode.appendChild(wrap);
      fields[f[0]] = inp;
    });

    var noteWrap = el('div', 'field');
    var noteLabel = el('label', null, t('f_note'));
    noteLabel.setAttribute('for', 'aisa-note');
    var note = document.createElement('textarea');
    note.id = 'aisa-note';
    noteWrap.appendChild(noteLabel);
    noteWrap.appendChild(note);
    formNode.appendChild(noteWrap);

    var fhp = document.createElement('input');
    fhp.className = 'hp';
    fhp.type = 'text';
    fhp.tabIndex = -1;
    fhp.setAttribute('aria-hidden', 'true');
    formNode.appendChild(fhp);

    var error = el('p', 'form-error');
    error.hidden = true;
    formNode.appendChild(error);

    var submit = el('button', 'submit', t('f_send'));
    submit.type = 'button';
    formNode.appendChild(submit);

    submit.addEventListener('click', function () {
      error.hidden = true;
      if (!fields.email.value.trim() && !fields.phone.value.trim()) {
        error.textContent = t('f_need');
        error.hidden = false;
        fields.email.focus();
        return;
      }
      submit.disabled = true;
      submit.textContent = t('f_sending');

      post('contact.php', {
        request_id: 'r' + randomId(23),
        session_id: W.session,
        visitor_id: visitorId(),
        page_url: location.href.slice(0, 500),
        name: fields.name.value,
        company: fields.company.value,
        email: fields.email.value,
        phone: fields.phone.value,
        note: note.value,
        company_website_hp: fhp.value
      }).then(function (res) {
        if (res && res.ok) {
          if (formNode && formNode.parentNode) { formNode.parentNode.removeChild(formNode); }
          formNode = null;
          addMessage('agent', [{ type: 'p', spans: [{ t: 'text', v: res.message || t('f_thanks') }] }], null);
        } else {
          error.textContent = (res && res.message) || 'That did not send. Check your details and try again.';
          error.hidden = false;
          submit.disabled = false;
          submit.textContent = t('f_send');
        }
      }).catch(function () {
        error.textContent = t('n_conn');
        error.hidden = false;
        submit.disabled = false;
        submit.textContent = t('f_send');
      });
    });

    panel.insertBefore(formNode, foot);
    scrollDown();
  }

  // ---------------------------------------------------------------------
  // Introduction card
  //
  // Four questions before the conversation: first name, company, the country
  // the company is based in, and exhibiting or visiting. Light on purpose,
  // and always skippable — a visitor with a quick question should never have
  // to fill in a form to ask it. Built with text nodes only, like everything
  // else here; nothing the server sends is ever parsed as markup.
  // ---------------------------------------------------------------------
  var introNode = null;

  function showIntroCard(countries) {
    if (introNode || formNode) { return; }
    foot.hidden = true;
    quick.hidden = true;

    introNode = el('div', 'form intro');

    // The language comes first: everything below, and the assistant's
    // replies, follow it (owner, 13 Nov 2026).
    var lWrap = el('div', 'field');
    var lLab = bind(el('label', null), 'language');
    lLab.setAttribute('for', 'aisa-intro-language');
    var langSel = document.createElement('select');
    langSel.id = 'aisa-intro-language';
    LANGS.forEach(function (l) {
      var o = document.createElement('option');
      o.value = l[0];
      o.textContent = l[1];
      if (l[0] === LANG) { o.selected = true; }
      langSel.appendChild(o);
    });
    langSel.addEventListener('change', function () { setLang(langSel.value); });
    lWrap.appendChild(lLab);
    lWrap.appendChild(langSel);
    introNode.appendChild(lWrap);

    introNode.appendChild(bind(el('p', 'intro-lead'), 'lead'));

    function textField(key, label, auto) {
      var wrap = el('div', 'field');
      var id = 'aisa-intro-' + key;
      var lab = bind(el('label', null), label);
      lab.setAttribute('for', id);
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.id = id;
      inp.maxLength = key === 'first_name' ? 60 : 160;
      inp.setAttribute('autocomplete', auto);
      wrap.appendChild(lab);
      wrap.appendChild(inp);
      introNode.appendChild(wrap);
      return inp;
    }

    var firstName = textField('first_name', 'first', 'given-name');
    var company = textField('company', 'company', 'organization');
    // Optional (owner, 12 Nov 2026): tells two companies of the same name apart.
    var website = textField('website', 'website', 'url');
    website.setAttribute('inputmode', 'url');
    website.placeholder = 'www.yourcompany.com';

    var cWrap = el('div', 'field');
    var cLab = bind(el('label', null), 'country');
    cLab.setAttribute('for', 'aisa-intro-country');
    var country = document.createElement('select');
    country.id = 'aisa-intro-country';
    var none = document.createElement('option');
    none.value = '';
    bind(none, 'choose');
    country.appendChild(none);
    (countries || []).forEach(function (c) {
      if (!c || c.length < 2) { return; }
      var o = document.createElement('option');
      o.value = String(c[0]);
      o.textContent = String(c[1]);
      country.appendChild(o);
    });
    cWrap.appendChild(cLab);
    cWrap.appendChild(country);
    introNode.appendChild(cWrap);

    var choice = document.createElement('fieldset');
    choice.className = 'choice';
    var legend = document.createElement('legend');
    bind(legend, 'interest');
    choice.appendChild(legend);
    var row = el('div', 'choice-row');
    var radios = {};
    [['exhibit', 'exhibit'], ['visit', 'visit']].forEach(function (opt) {
      var lab = document.createElement('label');
      var r = document.createElement('input');
      r.type = 'radio';
      r.name = 'aisa-intro-interest';
      r.value = opt[0];
      lab.appendChild(r);
      lab.appendChild(bind(document.createElement('span'), opt[1]));
      row.appendChild(lab);
      radios[opt[0]] = r;
    });
    choice.appendChild(row);
    introNode.appendChild(choice);

    var ihp = document.createElement('input');
    ihp.className = 'hp';
    ihp.type = 'text';
    ihp.tabIndex = -1;
    ihp.setAttribute('aria-hidden', 'true');
    introNode.appendChild(ihp);

    var error = el('p', 'form-error');
    error.hidden = true;
    error.setAttribute('role', 'alert');
    introNode.appendChild(error);

    var submit = bind(el('button', 'submit'), 'cont');
    submit.type = 'button';
    introNode.appendChild(submit);

    var skip = bind(el('button', 'skip'), 'skip');
    skip.type = 'button';
    introNode.appendChild(skip);

    function fail(text, focusOn) {
      error.textContent = text;
      error.hidden = false;
      if (focusOn) { focusOn.focus(); }
    }

    function closeCard() {
      if (introNode && introNode.parentNode) { introNode.parentNode.removeChild(introNode); }
      introNode = null;
      foot.hidden = false;
    }

    skip.addEventListener('click', function () {
      closeCard();
      setQuickReplies(W.quickReplies || []);
      input.focus();
    });

    submit.addEventListener('click', function () {
      error.hidden = true;
      var interest = radios.exhibit.checked ? 'exhibit' : (radios.visit.checked ? 'visit' : '');
      var name = firstName.value.trim();
      var org = company.value.trim();
      var code = country.value;

      if (!name) { fail(t('e_name'), firstName); return; }
      if (!interest) { fail(t('e_interest'), radios.exhibit); return; }
      if (interest === 'exhibit' && !org) {
        fail(t('e_company'), company); return;
      }
      if (interest === 'exhibit' && !code) {
        fail(t('e_country'), country); return;
      }

      submit.disabled = true;
      submit.textContent = t('wait');

      // What the visitor said, shown as their own message — the same words
      // the conversation record keeps.
      var countryName = code ? country.options[country.selectedIndex].textContent : '';
      var said = t('said_name', { name: name });
      if (org) { said += ' ' + (countryName ? t('said_from_country', { org: org, country: countryName }) : t('said_from', { org: org })); }
      else if (countryName) { said += ' ' + t('said_country', { country: countryName }); }
      said += ' ' + t(interest === 'exhibit' ? 'said_exhibit' : 'said_visit');

      // The card steps aside and their words appear with the typing dots,
      // while their company is looked up — nothing else is said.
      var card = introNode;
      if (card) { card.hidden = true; }
      var saidNode = addVisitorText(said);
      showTyping(null, true);
      function restoreCard() {
        hideTyping();
        if (saidNode && saidNode.parentNode) { saidNode.parentNode.removeChild(saidNode); }
        if (card) { card.hidden = false; }
      }

      post('intro.php', {
        request_id: 'r' + randomId(23),
        session_id: W.session,
        session_secret: W.secret,
        visitor_id: visitorId(),
        first_name: name,
        company: org,
        website: website.value.trim(),
        country: code,
        interest: interest,
        language: LANG,
        company_website_hp: ihp.value
      }).then(function (res) {
        if (res && res.ok) {
          hideTyping();
          closeCard();
          addMessage('agent', res.blocks || [], res.cards || null);
          if (res.contact_form) { showContactForm(null); }
          else { input.focus(); }
          return;
        }
        restoreCard();
        submit.disabled = false;
        submit.textContent = t('cont');
        if (res && res.error === 'session_expired') {
          fail(t('e_timeout'));
        } else if (res && res.error === 'already_done') {
          closeCard();
          input.focus();
        } else {
          fail((res && res.message) || t('e_generic'));
        }
      }).catch(function () {
        restoreCard();
        submit.disabled = false;
        submit.textContent = t('cont');
        fail(t('e_generic'));
      });
    });

    // Inside the conversation, so it scrolls with it on a small screen.
    log.appendChild(introNode);
    scrollDown();
  }

  // ---------------------------------------------------------------------
  // Networking
  // ---------------------------------------------------------------------
  function post(endpoint, body) {
    body.agent = AGENT;
    // The slug also goes in the query string. A JSON content type makes this
    // a non-simple cross-origin request, so the browser sends an OPTIONS
    // pre-flight first — and a pre-flight carries no body, so the server can
    // only identify the agent from the URL. Without this the pre-flight is
    // refused and the real request is never sent, from any other domain.
    var url = BASE + '/api/' + endpoint + '?agent=' + encodeURIComponent(AGENT);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'omit',
      mode: 'cors'
    }).then(function (r) {
      return r.json().then(function (data) {
        data.__status = r.status;
        return data;
      }).catch(function () {
        return { ok: false, error: 'bad_response', __status: r.status };
      });
    });
  }

  function startSession() {
    if (W.started) { return Promise.resolve(true); }
    W.started = true;
    headStatus.textContent = 'Connecting';

    var personal = PERSONAL;
    PERSONAL = null;                       // used once; "Not you?" starts a plain chat
    return post('session.php', {
      visitor_id: visitorId(),
      page_url: location.href.slice(0, 500),
      arrival: readArrival(),
      personal: personal
    }).then(function (res) {
      if (!res || !res.ok) {
        headStatus.textContent = 'Unavailable';
        showNotice('The assistant could not be reached. Leave your details and the team will follow up.');
        showContactForm(null);
        return false;
      }

      W.session = res.session_id;
      W.secret = res.session_secret;
      W.agentName = res.agent_name || 'Sales assistant';
      headTitle.textContent = W.agentName;
      headStatus.textContent = res.unavailable ? 'Offline' : 'Usually replies in a moment';

      sessionSet(STORAGE_SESSION, JSON.stringify({ id: W.session, s: W.secret }));

      // AI disclosure and privacy link (Section 44).
      while (disclosure.firstChild) { disclosure.removeChild(disclosure.firstChild); }
      disclosure.appendChild(document.createTextNode(res.disclosure || 'You are chatting with an AI assistant.'));
      if (res.privacy_url && /^https:\/\//i.test(res.privacy_url)) {
        disclosure.appendChild(document.createTextNode(' '));
        var pa = document.createElement('a');
        pa.href = res.privacy_url;
        pa.target = '_blank';
        pa.rel = 'noopener noreferrer';
        pa.textContent = 'Privacy notice';
        disclosure.appendChild(pa);
      }

      if (res.blocks && res.blocks.length) {
        addMessage('agent', res.blocks, null);
      }

      W.quickReplies = res.quick_replies || [];
      if (res.personal) {
        // Their language from an earlier chat, unless they chose one on this device.
        if (res.language && TEXT[res.language] && !storageGet(STORAGE_LANG)) { setLang(res.language); }
        var notYou = bind(el('button', 'skip not-you'), 'not_you');
        notYou.type = 'button';
        notYou.addEventListener('click', function () {
          sessionSet(STORAGE_PERSONAL, '');
          if (notYou.parentNode) { notYou.parentNode.removeChild(notYou); }
          while (log.firstChild) { log.removeChild(log.firstChild); }
          W.started = false;
          W.session = null;
          startSession();
        });
        log.appendChild(notYou);
        input.focus();
      } else if (res.unavailable || res.contact_form) {
        showContactForm(null);
      } else if (res.intro_form) {
        showIntroCard(res.countries);
      } else {
        setQuickReplies(res.quick_replies);
      }
      return true;
    }).catch(function () {
      headStatus.textContent = 'Unavailable';
      showNotice('The assistant could not be reached. Leave your details and the team will follow up.');
      showContactForm(null);
      return false;
    });
  }

  function setBusy(state) {
    W.busy = state;
    sendBtn.disabled = state;
    input.disabled = state;
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || W.busy || !W.session) { return; }
    if (text.length > W.maxChars) { text = text.slice(0, W.maxChars); }

    quick.hidden = true;
    addVisitorText(text);
    input.value = '';
    autoGrow();
    updateCounter();
    setBusy(true);
    showTyping();
    sendTurn(text, 0);
  }

  // The AI was busy even after the server waited. The message is kept on the
  // server, so the same message is sent again a little later, marked as a
  // retry so it is answered rather than stored twice.
  var MAX_BUSY_RETRIES = 5;

  function sendTurn(text, attempt) {
    var requestId = 'r' + randomId(23);

    post('chat.php', {
      request_id: requestId,
      session_id: W.session,
      session_secret: W.secret,
      visitor_id: visitorId(),
      message: text,
      retry: attempt > 0,
      language: LANG,
      company_website_hp: hp.value
    }).then(function (res) {
      if (res && res.ok && res.fallback === 'llm_busy') {
        if (attempt < MAX_BUSY_RETRIES) {
          var wait = Math.max(5, Math.min(60, parseInt(res.retry_after, 10) || 12));
          showTyping(t('many'));
          window.setTimeout(function () { sendTurn(text, attempt + 1); }, wait * 1000);
          return;
        }
        hideTyping();
        setBusy(false);
        showRetry(text);
        return;
      }

      hideTyping();
      setBusy(false);

      if (!res || !res.ok) {
        var code = res && res.error;
        if (code === 'rate_limited') {
          showNotice(t('n_fast'));
        } else if (code === 'message_too_long') {
          showNotice(t('n_long'));
        } else if (code === 'session_expired') {
          showNotice('This chat timed out. Reload the page to start again.');
          setBusy(true);
        } else {
          showNotice(t('n_fail'));
        }
        return;
      }

      addMessage('agent', res.blocks, res.cards);

      if (res.contact_form) {
        showContactForm(null);
      }
      input.focus();
    }).catch(function () {
      hideTyping();
      setBusy(false);
      showNotice(t('n_conn'));
    });
  }

  // ---------------------------------------------------------------------
  // Interaction
  // ---------------------------------------------------------------------
  function openPanel() {
    if (W.open) { return; }
    W.open = true;
    panel.hidden = false;
    launcher.hidden = true;
    launcher.setAttribute('aria-expanded', 'true');

    startSession().then(function () {
      if (!formNode && !introNode) { input.focus(); }
    });
  }

  function closePanel() {
    W.open = false;
    panel.hidden = true;
    launcher.hidden = false;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  function toggleWide() {
    W.wide = !W.wide;
    panel.className = W.wide ? 'panel is-wide' : 'panel';
    while (expandBtn.firstChild) { expandBtn.removeChild(expandBtn.firstChild); }
    expandBtn.appendChild(svg(W.wide ? ICON.collapse : ICON.expand));
    var label = W.wide ? 'Return to the smaller panel' : 'Make the panel larger';
    expandBtn.title = label;
    expandBtn.setAttribute('aria-label', label);
    scrollDown();
  }

  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(120, input.scrollHeight) + 'px';
  }

  function updateCounter() {
    var len = input.value.length;
    if (len > W.maxChars * 0.8) {
      counter.textContent = len + ' / ' + W.maxChars;
      counter.className = len > W.maxChars ? 'counter is-over' : 'counter';
    } else {
      counter.textContent = '';
      counter.className = 'counter';
    }
  }

  launcher.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  expandBtn.addEventListener('click', toggleWide);
  sendBtn.addEventListener('click', function () { send(input.value); });

  humanBtn.addEventListener('click', function () {
    if (W.busy) { return; }
    send(LANG === 'en' ? 'I would like to speak to someone from your team.' : t('person'));
  });

  input.addEventListener('input', function () { autoGrow(); updateCounter(); });
  input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      send(input.value);
    }
  });

  shadow.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && W.open) { closePanel(); }
  });

  if (AUTO_OPEN || PERSONAL_FRESH) {
    window.setTimeout(openPanel, PERSONAL_FRESH ? 600 : 1200);
  }

  // Public hook, so a site can open the widget from its own button.
  window.AISalesAgent = {
    version: WIDGET_VERSION,
    agent: AGENT,
    base: BASE,
    mounted: function () { return !!(host && host.parentNode); },
    open: openPanel,
    close: closePanel,
    isOpen: function () { return W.open; }
  };
})();
