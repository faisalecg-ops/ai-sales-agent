<?php
declare(strict_types=1);

/**
 * The chat's languages (owner, 13 Nov 2026).
 *
 * The visitor chooses a language on the chat's first card (English by
 * default). The AI writes its replies in that language; the few lines the
 * program writes itself (the quotation lines, the sales rep's details, the
 * personal-link welcome) come from the table below; and the words the program
 * listens for (a price question, "send me a quotation", "yes", "speak to a
 * person"...) are recognised in every one of these languages, whatever was
 * chosen, because visitors write in their own language anyway.
 *
 * Urdu is Roman Urdu (Urdu in Latin letters), as the owner asked.
 * Quotation PDFs stay in English (owner, 13 Nov 2026).
 */
final class Lang
{
    /** code => [English name, own name, right to left] */
    public const LANGUAGES = [
        'en' => ['English', 'English', false],
        'zh' => ['Chinese', '中文', false],
        'ur' => ['Urdu (Roman)', 'Urdu (Roman)', false],
        'ar' => ['Arabic', 'العربية', true],
        'tr' => ['Turkish', 'Türkçe', false],
        'ru' => ['Russian', 'Русский', false],
        'es' => ['Spanish', 'Español', false],
        'fr' => ['French', 'Français', false],
        'de' => ['German', 'Deutsch', false],
        'ko' => ['Korean', '한국어', false],
    ];

    public static function valid(?string $code): ?string
    {
        $code = strtolower(trim((string)$code));
        return isset(self::LANGUAGES[$code]) ? $code : null;
    }

    public static function name(?string $code): string
    {
        return self::LANGUAGES[self::valid($code) ?? 'en'][0];
    }

    /** The visitor's language in this conversation: their choice, or English. */
    public static function of(array $leadState): string
    {
        return self::valid($leadState['language'] ?? null) ?? 'en';
    }

    // -----------------------------------------------------------------
    // What the AI is told
    // -----------------------------------------------------------------

    public static function promptBlock(string $code): string
    {
        $code = self::valid($code) ?? 'en';
        if ($code === 'en') {
            return "LANGUAGE\nWrite your replies in English. Only if the visitor's own message is clearly written in another "
                 . "language, reply in that language instead. Roman Urdu (Urdu in Latin letters) is answered in Roman Urdu.";
        }
        $name = self::LANGUAGES[$code][0];
        $how  = $code === 'ur'
            ? 'Roman Urdu: Urdu written in Latin letters, the way people type it on WhatsApp (for example "Aap ka shukriya"). Never use Urdu script.'
            : $name . '.';
        return "LANGUAGE\nThe visitor chose " . $name . ". Write every reply in " . $how . " Only if the visitor's own message "
             . "is clearly written in another language, reply in that language instead.\n"
             . "Keep these exactly as they are given to you, untranslated: prices and currency (for example USD 6,750), "
             . "the names of stands and sponsorship tiers, sizes in sqm, email addresses, phone numbers and web addresses.\n"
             . "Everything in the JSON other than \"reply\" (lead_fields, qualification and the rest) is written in English.";
    }

    // -----------------------------------------------------------------
    // Lines the program writes itself
    // -----------------------------------------------------------------

    private const TEXT = [
        'quote_offer' => [
            'en' => 'I can also email you an official quotation for this. Just reply "yes".',
            'zh' => '我也可以将正式报价单通过电子邮件发送给您。如需要，请回复"是"。',
            'ur' => 'Main is ki official quotation aap ko email bhi kar sakta hoon. Bas "haan" likh dein.',
            'ar' => 'يمكنني أيضاً إرسال عرض سعر رسمي لهذا إلى بريدك الإلكتروني. فقط اكتب "نعم".',
            'tr' => 'Bunun için size e-posta ile resmi bir fiyat teklifi de gönderebilirim. Sadece "evet" yazın.',
            'ru' => 'Я также могу отправить вам официальное коммерческое предложение по электронной почте. Просто ответьте «да».',
            'es' => 'También puedo enviarle una cotización oficial por correo electrónico. Solo responda "sí".',
            'fr' => 'Je peux aussi vous envoyer un devis officiel par e-mail. Répondez simplement « oui ».',
            'de' => 'Ich kann Ihnen dafür auch ein offizielles Angebot per E-Mail senden. Antworten Sie einfach mit „Ja".',
            'ko' => '이에 대한 공식 견적서를 이메일로 보내 드릴 수도 있습니다. "네"라고 답해 주세요.',
        ],
        'quote_sent' => [
            'en' => 'Quotation {number} is on its way to {email}. It is valid until {date}.',
            'zh' => '报价单 {number} 正在发送至 {email}，有效期至 {date}。',
            'ur' => 'Quotation {number} {email} par bheji ja rahi hai. Yeh {date} tak valid hai.',
            'ar' => 'عرض السعر {number} في طريقه إلى {email}، وهو صالح حتى {date}.',
            'tr' => '{number} numaralı teklif {email} adresine gönderiliyor. {date} tarihine kadar geçerlidir.',
            'ru' => 'Предложение {number} отправляется на {email}. Оно действительно до {date}.',
            'es' => 'La cotización {number} se está enviando a {email}. Es válida hasta el {date}.',
            'fr' => 'Le devis {number} est en route vers {email}. Il est valable jusqu\'au {date}.',
            'de' => 'Angebot {number} ist unterwegs an {email}. Es gilt bis {date}.',
            'ko' => '견적서 {number}을(를) {email}(으)로 보내고 있습니다. {date}까지 유효합니다.',
        ],
        'rep_one' => [
            'en' => 'You can also reach them directly:', 'zh' => '您也可以直接联系：', 'ur' => 'Aap in se seedha bhi raabta kar sakte hain:',
            'ar' => 'يمكنك أيضاً التواصل معهم مباشرة:', 'tr' => 'Kendisine doğrudan da ulaşabilirsiniz:',
            'ru' => 'Вы также можете связаться напрямую:', 'es' => 'También puede contactar directamente con:',
            'fr' => 'Vous pouvez aussi contacter directement :', 'de' => 'Sie können sich auch direkt wenden an:',
            'ko' => '다음 담당자에게 직접 연락하실 수도 있습니다:',
        ],
        'rep_both' => [
            'en' => 'You can reach our team directly:', 'zh' => '您可以直接联系我们的团队：', 'ur' => 'Aap hamari team se seedha raabta kar sakte hain:',
            'ar' => 'يمكنك التواصل مع فريقنا مباشرة:', 'tr' => 'Ekibimize doğrudan ulaşabilirsiniz:',
            'ru' => 'Вы можете связаться с нашей командой напрямую:', 'es' => 'Puede contactar directamente con nuestro equipo:',
            'fr' => 'Vous pouvez contacter directement notre équipe :', 'de' => 'Sie erreichen unser Team direkt:',
            'ko' => '저희 팀에 직접 연락하실 수 있습니다:',
        ],
        'rep_local' => [
            'en' => 'For companies based in Pakistan', 'zh' => '巴基斯坦公司', 'ur' => 'Pakistan ki companiyon ke liye',
            'ar' => 'للشركات الموجودة في باكستان', 'tr' => 'Pakistan\'daki şirketler için', 'ru' => 'Для компаний из Пакистана',
            'es' => 'Para empresas con sede en Pakistán', 'fr' => 'Pour les entreprises basées au Pakistan',
            'de' => 'Für Unternehmen mit Sitz in Pakistan', 'ko' => '파키스탄 소재 기업',
        ],
        'rep_intl' => [
            'en' => 'For companies based outside Pakistan', 'zh' => '巴基斯坦以外的公司', 'ur' => 'Pakistan se bahar ki companiyon ke liye',
            'ar' => 'للشركات الموجودة خارج باكستان', 'tr' => 'Pakistan dışındaki şirketler için', 'ru' => 'Для компаний за пределами Пакистана',
            'es' => 'Para empresas con sede fuera de Pakistán', 'fr' => 'Pour les entreprises basées hors du Pakistan',
            'de' => 'Für Unternehmen mit Sitz außerhalb Pakistans', 'ko' => '파키스탄 외 지역 기업',
        ],
        'mobile' => ['en' => 'Mobile', 'zh' => '手机', 'ur' => 'Mobile', 'ar' => 'الجوال', 'tr' => 'Cep', 'ru' => 'Мобильный',
                     'es' => 'Móvil', 'fr' => 'Mobile', 'de' => 'Mobil', 'ko' => '휴대폰'],
        'email' => ['en' => 'Email', 'zh' => '邮箱', 'ur' => 'Email', 'ar' => 'البريد', 'tr' => 'E-posta', 'ru' => 'Эл. почта',
                    'es' => 'Correo', 'fr' => 'E-mail', 'de' => 'E-Mail', 'ko' => '이메일'],
        'with_tax' => ['en' => 'with', 'zh' => '含', 'ur' => 'ke saath', 'ar' => 'شاملاً', 'tr' => 'dahil', 'ru' => 'с учётом',
                       'es' => 'con', 'fr' => 'avec', 'de' => 'inkl.', 'ko' => '포함'],
        // The welcome when someone opens their personal chat link.
        'link_quote' => [
            'en' => 'Welcome back, {name}. Thank you for following up on quotation {number}. How can I help you further?',
            'zh' => '{name}，欢迎回来。感谢您跟进报价单 {number}。我还能为您做些什么？',
            'ur' => '{name}, khush aamdeed. Quotation {number} par raabta karne ka shukriya. Main aap ki aur kya madad kar sakta hoon?',
            'ar' => 'أهلاً بعودتك يا {name}. شكراً لمتابعتك عرض السعر {number}. كيف يمكنني مساعدتك أكثر؟',
            'tr' => 'Tekrar hoş geldiniz {name}. {number} numaralı teklifle ilgilendiğiniz için teşekkürler. Size başka nasıl yardımcı olabilirim?',
            'ru' => 'С возвращением, {name}. Спасибо, что вернулись к предложению {number}. Чем ещё я могу помочь?',
            'es' => 'Bienvenido de nuevo, {name}. Gracias por darle seguimiento a la cotización {number}. ¿En qué más puedo ayudarle?',
            'fr' => 'Bon retour, {name}. Merci de donner suite au devis {number}. Comment puis-je vous aider davantage ?',
            'de' => 'Willkommen zurück, {name}. Danke, dass Sie auf Angebot {number} zurückkommen. Wie kann ich Ihnen weiterhelfen?',
            'ko' => '{name}님, 다시 오신 것을 환영합니다. 견적서 {number}에 관심을 가져 주셔서 감사합니다. 무엇을 더 도와드릴까요?',
        ],
        'link_prospect' => [
            'en' => 'Welcome, {name}. Thank you for following up on our email about {exhibition}. How can I help you?',
            'zh' => '{name}，欢迎。感谢您回应我们关于 {exhibition} 的邮件。请问有什么可以帮您？',
            'ur' => '{name}, khush aamdeed. {exhibition} ke baare mein hamari email par raabta karne ka shukriya. Main aap ki kya madad kar sakta hoon?',
            'ar' => 'أهلاً بك يا {name}. شكراً لمتابعتك رسالتنا حول {exhibition}. كيف يمكنني مساعدتك؟',
            'tr' => 'Hoş geldiniz {name}. {exhibition} hakkındaki e-postamıza dönüş yaptığınız için teşekkürler. Size nasıl yardımcı olabilirim?',
            'ru' => 'Здравствуйте, {name}. Спасибо, что откликнулись на наше письмо о {exhibition}. Чем могу помочь?',
            'es' => 'Bienvenido, {name}. Gracias por responder a nuestro correo sobre {exhibition}. ¿En qué puedo ayudarle?',
            'fr' => 'Bienvenue, {name}. Merci d\'avoir donné suite à notre e-mail sur {exhibition}. Comment puis-je vous aider ?',
            'de' => 'Willkommen, {name}. Danke, dass Sie auf unsere E-Mail zu {exhibition} reagieren. Wie kann ich Ihnen helfen?',
            'ko' => '{name}님, 환영합니다. {exhibition} 관련 이메일에 회신해 주셔서 감사합니다. 무엇을 도와드릴까요?',
        ],
        'link_whatsapp' => [
            'en' => 'Welcome, {name}. Thank you for your interest in {exhibition}. How can I help you?',
            'zh' => '{name}，欢迎。感谢您关注 {exhibition}。请问有什么可以帮您？',
            'ur' => '{name}, khush aamdeed. {exhibition} mein dilchaspi ka shukriya. Main aap ki kya madad kar sakta hoon?',
            'ar' => 'أهلاً بك يا {name}. شكراً لاهتمامك بـ {exhibition}. كيف يمكنني مساعدتك؟',
            'tr' => 'Hoş geldiniz {name}. {exhibition} ile ilgilendiğiniz için teşekkürler. Size nasıl yardımcı olabilirim?',
            'ru' => 'Здравствуйте, {name}. Спасибо за интерес к {exhibition}. Чем могу помочь?',
            'es' => 'Bienvenido, {name}. Gracias por su interés en {exhibition}. ¿En qué puedo ayudarle?',
            'fr' => 'Bienvenue, {name}. Merci de votre intérêt pour {exhibition}. Comment puis-je vous aider ?',
            'de' => 'Willkommen, {name}. Danke für Ihr Interesse an {exhibition}. Wie kann ich Ihnen helfen?',
            'ko' => '{name}님, 환영합니다. {exhibition}에 관심을 가져 주셔서 감사합니다. 무엇을 도와드릴까요?',
        ],
        // What the chat card said, as the visitor's own words.
        'said_name' => [
            'en' => 'My name is {name}.', 'zh' => '我叫{name}。', 'ur' => 'Mera naam {name} hai.', 'ar' => 'اسمي {name}.',
            'tr' => 'Adım {name}.', 'ru' => 'Меня зовут {name}.', 'es' => 'Me llamo {name}.', 'fr' => 'Je m\'appelle {name}.',
            'de' => 'Mein Name ist {name}.', 'ko' => '제 이름은 {name}입니다.',
        ],
        'said_from_country' => [
            'en' => 'I am from {org}, based in {country}.', 'zh' => '我来自{org}，公司位于{country}。',
            'ur' => 'Main {org} se hoon, hamari company {country} mein hai.', 'ar' => 'أنا من {org}، ومقرها في {country}.',
            'tr' => '{org} firmasındanım, merkezimiz {country}.', 'ru' => 'Я из компании {org}, она находится в стране {country}.',
            'es' => 'Soy de {org}, con sede en {country}.', 'fr' => 'Je travaille chez {org}, basée en {country}.',
            'de' => 'Ich bin von {org} mit Sitz in {country}.', 'ko' => '저는 {country}에 있는 {org} 소속입니다.',
        ],
        'said_from' => [
            'en' => 'I am from {org}.', 'zh' => '我来自{org}。', 'ur' => 'Main {org} se hoon.', 'ar' => 'أنا من {org}.',
            'tr' => '{org} firmasındanım.', 'ru' => 'Я из компании {org}.', 'es' => 'Soy de {org}.', 'fr' => 'Je travaille chez {org}.',
            'de' => 'Ich bin von {org}.', 'ko' => '저는 {org} 소속입니다.',
        ],
        'said_country' => [
            'en' => 'I am based in {country}.', 'zh' => '我在{country}。', 'ur' => 'Main {country} mein hoon.', 'ar' => 'أنا في {country}.',
            'tr' => '{country} merkezliyim.', 'ru' => 'Я нахожусь в стране {country}.', 'es' => 'Estoy en {country}.',
            'fr' => 'Je suis basé en {country}.', 'de' => 'Ich bin in {country}.', 'ko' => '저는 {country}에 있습니다.',
        ],
        'said_exhibit' => [
            'en' => 'I am interested in exhibiting.', 'zh' => '我有意参展。', 'ur' => 'Mujhe exhibit karne mein dilchaspi hai.',
            'ar' => 'أنا مهتم بالمشاركة كعارض.', 'tr' => 'Katılımcı olarak katılmakla ilgileniyorum.', 'ru' => 'Меня интересует участие в качестве экспонента.',
            'es' => 'Me interesa exponer.', 'fr' => 'Je souhaite exposer.', 'de' => 'Ich interessiere mich für eine Teilnahme als Aussteller.',
            'ko' => '전시 참가에 관심이 있습니다.',
        ],
        'said_visit' => [
            'en' => 'I am interested in visiting.', 'zh' => '我有意参观。', 'ur' => 'Mujhe visit karne mein dilchaspi hai.',
            'ar' => 'أنا مهتم بالزيارة.', 'tr' => 'Ziyaret etmekle ilgileniyorum.', 'ru' => 'Меня интересует посещение выставки.',
            'es' => 'Me interesa visitar.', 'fr' => 'Je souhaite visiter.', 'de' => 'Ich interessiere mich für einen Besuch.',
            'ko' => '방문에 관심이 있습니다.',
        ],
    ];

    /** A line in the visitor's language, with {placeholders} filled in. */
    public static function t(?string $code, string $key, array $vars = []): string
    {
        $row  = self::TEXT[$key] ?? [];
        $text = (string)($row[self::valid($code) ?? 'en'] ?? $row['en'] ?? $key);
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    // -----------------------------------------------------------------
    // Words the program listens for, in every language
    // -----------------------------------------------------------------

    private const WORDS = [
        // Asking a price
        'price' => [
            '价格', '价钱', '多少钱', '费用', '报价', '收费', '价位', '几钱',
            'qeemat', 'qimat', 'keemat', 'kimat', 'kitne ka', 'kitne ki', 'kitna kharcha', 'kharcha kitna', 'kitne paise', 'rate kya', 'price kya',
            'سعر', 'أسعار', 'الأسعار', 'تكلفة', 'التكلفة', 'كم يكلف', 'بكم', 'رسوم',
            'fiyat', 'fiyatı', 'fiyatlar', 'ücret', 'maliyet', 'ne kadar', 'kaç para',
            'цена', 'цены', 'стоимость', 'сколько стоит', 'расценки', 'прайс',
            'precio', 'precios', 'costo', 'coste', 'cuánto cuesta', 'cuanto cuesta', 'cuánto vale', 'tarifa', 'tarifas',
            'prix', 'tarif', 'tarifs', 'coût', 'combien coûte', 'combien ça coûte', 'combien coute',
            'preis', 'preise', 'kosten', 'kostet', 'was kostet', 'wie viel', 'wieviel',
            '가격', '비용', '얼마', '요금', '견적',
        ],
        // Asking for a quotation in writing
        'quote' => [
            '报价单', '报价', '报价函', '形式发票',
            'quotation bhej', 'quotation chahiye', 'quotation de', 'quote bhej', 'proposal bhej', 'proposal chahiye',
            'عرض سعر', 'عرض أسعار', 'عرض الأسعار', 'فاتورة مبدئية',
            'teklif', 'fiyat teklifi', 'proforma',
            'коммерческое предложение', 'коммерческое', 'котировк', 'счет-проформ',
            'cotización', 'cotizacion', 'presupuesto', 'propuesta', 'proforma',
            'devis', 'proposition commerciale', 'offre de prix',
            'angebot', 'kostenvoranschlag', 'proforma',
            '견적서', '견적',
        ],
        // A short "yes" to an offer
        'yes' => [
            '是', '好', '好的', '可以', '行', '要', '需要', '是的', '请发', '发给我',
            'haan', 'han', 'haa', 'ji haan', 'jee haan', 'ji han', 'ji', 'jee', 'theek hai', 'thik hai', 'bilkul', 'zaroor', 'bhej do', 'bhej dein',
            'نعم', 'أجل', 'حسنا', 'حسناً', 'موافق', 'تمام', 'أرسل', 'ارسل',
            'evet', 'olur', 'tamam', 'lütfen', 'gönderin',
            'да', 'конечно', 'хорошо', 'пришлите', 'отправьте',
            'sí', 'si', 'claro', 'vale', 'por favor', 'envíela', 'envíemela',
            'oui', 'd\'accord', 'volontiers', 'envoyez',
            'ja', 'gerne', 'gern', 'bitte', 'senden sie',
            '네', '예', '좋아요', '좋습니다', '보내 주세요', '보내주세요',
        ],
        // How do we go ahead?
        'next' => [
            '下一步', '怎么报名', '如何报名', '如何预订', '怎么预订', '如何参展', '怎么参展', '预订', '签约',
            'aage kaise', 'agla qadam', 'kaise book', 'booking kaise', 'aage kya', 'kaise shuru',
            'الخطوة التالية', 'كيف نحجز', 'كيف أحجز', 'حجز',
            'sonraki adım', 'nasıl rezervasyon', 'rezervasyon', 'nasıl devam',
            'следующий шаг', 'как забронировать', 'бронирование', 'как действовать',
            'siguiente paso', 'próximo paso', 'cómo reservar', 'como reservar', 'reservar',
            'prochaine étape', 'comment réserver', 'réserver',
            'nächste schritt', 'nächsten schritt', 'wie buchen', 'wie können wir buchen', 'buchen',
            '다음 단계', '예약', '어떻게 진행',
        ],
        // A person, please
        'person' => [
            '人工客服', '找人工', '真人', '和销售人员', '销售人员联系', '打电话给我',
            'banday se baat', 'bande se baat', 'insaan se baat', 'kisi se baat', 'call karein', 'phone karein', 'representative se',
            'التحدث مع شخص', 'أريد التحدث مع', 'اتصل بي',
            'bir kişiyle', 'temsilciyle', 'beni arayın',
            'поговорить с человеком', 'с человеком', 'с менеджером', 'позвоните мне', 'живой человек',
            'hablar con una persona', 'hablar con alguien', 'llámeme', 'llamenme',
            'parler à quelqu\'un', 'parler à une personne', 'un conseiller', 'appelez-moi', 'un humain',
            'mit einem menschen', 'einen ansprechpartner', 'rufen sie mich an',
            '상담원', '담당자와', '전화 주세요',
        ],
        // Discounts, contracts, invoices, payment: a person decides
        'money' => [
            '折扣', '优惠', '便宜点', '合同', '发票', '付款',
            'riayat', 'qeemat kam', 'kam kar dein',
            'خصم', 'تخفيض', 'العقد', 'فاتورة', 'طريقة الدفع',
            'indirim', 'sözleşme', 'fatura', 'ödeme',
            'скидка', 'скидку', 'договор', 'счёт на оплату', 'оплата',
            'descuento', 'contrato', 'factura', 'pago',
            'remise', 'réduction', 'contrat', 'facture', 'paiement',
            'rabatt', 'vertrag', 'rechnung', 'zahlung',
            '할인', '계약', '송장', '결제',
        ],
        // No, thank you (short answers only)
        'decline' => [
            '不用', '不要', '不想', '不方便', '不需要',
            'nahi', 'nahin', 'nai', 'zaroorat nahi', 'abhi nahi', 'email nahi',
            'لا', 'لا شكرا', 'لا شكراً', 'لا أريد',
            'hayır', 'istemiyorum', 'gerek yok',
            'нет', 'не надо', 'не хочу',
            'no gracias', 'prefiero no',
            'non', 'non merci', 'je préfère pas',
            'nein', 'lieber nicht', 'nein danke',
            '아니요', '괜찮습니다', '아니오',
        ],
        // A stand, and sponsorship
        'stand' => ['展位', '展台', '摊位', '标摊', '光地', '标准展位', 'جناح', 'منصة', 'стенд', '부스', 'stand', 'stall', 'stant'],
        'sponsor' => ['赞助', 'رعاية', 'راعي', 'спонсор', 'patrocinio', 'parrainage', 'sponsoring', 'sponsorluk', '스폰서', '후원'],
    ];

    /** Does the message use any of these words, in any of the languages? */
    public static function has(string $message, string $kind): bool
    {
        $text = mb_strtolower($message);
        foreach (self::WORDS[$kind] ?? [] as $word) {
            if (self::contains($text, mb_strtolower($word))) {
                return true;
            }
        }
        return false;
    }

    /** A short message (up to 40 characters) that starts with one of these words. */
    public static function startsWith(string $message, string $kind): bool
    {
        $text = trim(mb_strtolower($message));
        if ($text === '' || mb_strlen($text) > 40) {
            return false;
        }
        foreach (self::WORDS[$kind] ?? [] as $word) {
            $w = mb_strtolower($word);
            if (str_starts_with($text, $w)) {
                // A Latin word must end there ("si" is not "simple"); Chinese,
                // Korean and Arabic words need no space after them.
                $next = mb_substr($text, mb_strlen($w), 1);
                if (!self::latin($w) || $next === '' || !preg_match('/[\p{L}\p{N}]/u', $next)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function contains(string $text, string $word): bool
    {
        if ($word === '') {
            return false;
        }
        if (!self::latin($word)) {
            return str_contains($text, $word);
        }
        return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $text);
    }

    private static function latin(string $word): bool
    {
        return (bool)preg_match('/^[\p{Latin}\p{N}\s\'\-.]+$/u', $word);
    }
}
