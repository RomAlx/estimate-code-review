# Estimate Code Review 🚀

Инструмент для оценки стоимости разработки на основе анализа GitHub репозиториев с использованием машинного обучения.

## Особенности 📋

- Оценка всего репозитория по выбранной ветке
- Оценка отдельных коммитов
- Подробная статистика по изменениям кода
- Экспорт результатов в CSV
- Поддержка приватных репозиториев
- Машинное обучение для точности оценок

## Установка 🛠

1. Клонируйте репозиторий:
```bash
git clone https://github.com/RomAlx/estimate-code-review.git
cd estimate-code-review
```

2. Установите зависимости:
```bash
composer install
```

3. Создайте файл `.env` и добавьте токен GitHub:
```
REPO_TOKEN=ваш_github_token
```

Для получения токена:
1. Перейдите на https://github.com/settings/tokens
2. Создайте новый токен (Generate new token)
3. Выберите scope `repo`
4. Скопируйте токен в `.env` файл

## Использование 💡

### Обучение модели
```bash
bin/console train
```

### Анализ репозитория
```bash
bin/console estimate:repository author/repository [--branch=branch_name]
```
Если ветка не указана, используется master или основная ветка репозитория.

### Оценка отдельного коммита
```bash
bin/console estimate:commit author/repository commit_hash
```

## Примеры вывода 📊

### Анализ репозитория
```
📊 Анализ репозитория author/repository

✓ Подключение к GitHub: успешно
✓ Проверка доступа к репозиторию: успешно

ℹ️ Доступные ветки:
  • main (основная)
  • develop
  • feature/auth

⚡ Начинаем анализ ветки main
[1/134] 8f3d21c3 Initial commit | +150 -0 | 3 файла | 4 185,00 $
...

📊 Итоговая статистика:
╔════════════════════════╤══════════════╗
║ Всего коммитов        │ 134          ║
║ Добавлено строк       │ 15 420       ║
║ Удалено строк         │ 3 250        ║
║ Изменено файлов       │ 456          ║
║ Общая стоимость       │ 57 875 $     ║
║ Средняя цена коммита  │ 241 $        ║
╚════════════════════════╧══════════════╝
```

### Оценка коммита
```
📊 Анализ коммита 8f3d21c3

Автор: John Doe
Дата: 2024-12-23 15:49:54
Сообщение: Add authentication feature

Статистика:
- Добавлено строк: 1
- Удалено строк: 1
- Изменено файлов: 1
- Стоимость: $4.74
```

## Структура проекта 📁

```
.
├── bin/
│   └── console              # Исполняемый файл
├── data/
│   ├── code-reviews.csv     # Данные для обучения
└── src/
    └── Command/            # Команды консоли
        ├── TrainCommand.php
        ├── EstimateRepositoryCommand.php
        └── EstimateCommitCommand.php
```

## Технологии 🔧

- PHP 7.1+
- Symfony Console Component
- PHP-ML (Machine Learning)
- GitHub API

## Лицензия 📄

MIT License. Подробности в файле [LICENSE](LICENSE).

## Автор ✨

Romanovsky Aleksey ([@RomAlx](https://github.com/RomAlx))

## Вклад в проект 🤝

Приветствуются любые предложения по улучшению! Для этого:
1. Форкните репозиторий
2. Создайте свою ветку (`git checkout -b feature/amazing`)
3. Зафиксируйте изменения (`git commit -am 'Add amazing feature'`)
4. Отправьте изменения (`git push origin feature/amazing`)
5. Создайте Pull Request

Также будем рады, если вы:
- Сообщите об ошибках через Issues
- Предложите новые функции
- Улучшите документацию