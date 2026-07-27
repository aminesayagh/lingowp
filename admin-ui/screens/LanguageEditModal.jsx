import {
  BlockStack,
  FormLayout,
  Modal,
  Select,
  Text,
  TextField,
} from "@shopify/polaris";
import { useEffect, useState } from "@wordpress/element";
import { api } from "../api";
import {
  AI_MODELS,
  AI_STYLE_MAX,
  AI_STYLE_PLACEHOLDER,
  LANGUAGE_CHOICES,
} from "./AddLanguageModal";

// Edit an existing target language (PATCH /languages/{code}). Mirrors the Add
// form minus Language (the code can't change). Status/delete live in the rail ⋯.
export default function LanguageEditModal({ open, lang, onClose, onSaved }) {
  const [displayName, setDisplayName] = useState("");
  const [slug, setSlug] = useState("");
  const [fallback, setFallback] = useState("");
  const [aiModel, setAiModel] = useState("auto");
  const [style, setStyle] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open && lang) {
      setDisplayName(lang.display_name || "");
      setSlug(lang.slug || "");
      setFallback(lang.fallback_language || "");
      setAiModel(lang.ai_model || "auto");
      setStyle(lang.ai_style || "");
    }
  }, [open, lang]);

  if (!lang) {
    return null;
  }

  async function save() {
    setSaving(true);
    const res = await api.patch(`/languages/${encodeURIComponent(lang.code)}`, {
      display_name: displayName,
      slug,
      fallback_language: fallback,
      ai_model: aiModel,
      ai_style: style,
    });
    setSaving(false);
    if (res.ok) {
      onSaved();
      onClose();
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Edit ${lang.display_name || lang.name || lang.code}`}
      primaryAction={{ content: "Save", onAction: save, loading: saving }}
      secondaryActions={[{ content: "Cancel", onAction: onClose }]}
    >
      <Modal.Section>
        <FormLayout>
          <BlockStack gap="100">
            <TextField
              label="Name"
              value={displayName}
              onChange={setDisplayName}
              autoComplete="off"
            />
            <TextField
              label="URL slug"
              value={slug}
              onChange={setSlug}
              autoComplete="off"
            />
            <Select
              label="Fallback language"
              options={[
                { label: "None", value: "" },
                ...LANGUAGE_CHOICES.map((c) => ({
                  label: c.label,
                  value: c.value,
                })),
              ]}
              value={fallback}
              onChange={setFallback}
            />
          </BlockStack>
        </FormLayout>
      </Modal.Section>
      <Modal.Section>
        <BlockStack gap="300">
          <Text as="h3" variant="headingSm">
            AI settings
          </Text>

          <BlockStack gap="100">
            <Select
              label="AI model"
              options={AI_MODELS}
              value={aiModel}
              onChange={setAiModel}
            />
            <TextField
              label="Style"
              multiline={2}
              maxLength={AI_STYLE_MAX}
              showCharacterCount
              value={style}
              onChange={setStyle}
              autoComplete="off"
              placeholder={AI_STYLE_PLACEHOLDER}
              helpText="Guide the tone and wording used for this language."
            />
          </BlockStack>
        </BlockStack>
      </Modal.Section>
    </Modal>
  );
}
