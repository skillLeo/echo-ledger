//! A provider the relay cannot reach: every request fails at once with "not configured", which the app shows as a
//! plain error on that suggestion. Captions and the rest of the session carry on.

use futures_util::future::BoxFuture;
use futures_util::{FutureExt, StreamExt, future, stream};

use super::{CompletionRequest, Provider, ProviderError, TokenStream, Usage};
use crate::domain::ProviderKind;

#[derive(Debug, Clone, Copy)]
pub struct Unconfigured {
    kind: ProviderKind,
}

impl Unconfigured {
    pub fn new(kind: ProviderKind) -> Self {
        Unconfigured { kind }
    }
}

impl Provider for Unconfigured {
    fn kind(&self) -> ProviderKind {
        self.kind
    }

    fn stream(&self, _request: CompletionRequest) -> TokenStream {
        stream::once(future::ready(Err(ProviderError::NotConfigured(self.kind)))).boxed()
    }

    fn warm(&self, _request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>> {
        future::ready(Err(ProviderError::NotConfigured(self.kind))).boxed()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;

    use crate::context::Context;
    use crate::provider::Purpose;

    #[tokio::test]
    async fn every_request_says_which_provider_is_not_configured() {
        let provider = Unconfigured::new(ProviderKind::Anthropic);
        let request = CompletionRequest {
            model: "claude-haiku-5-5".into(),
            context: Arc::new(Context::default()),
            max_output_tokens: 10,
            options: toml::Table::new(),
            purpose: Purpose::Answer,
            question: String::new(),
        };
        let first = provider.stream(request.clone()).next().await;
        assert_eq!(first, Some(Err(ProviderError::NotConfigured(ProviderKind::Anthropic))));
        let error = provider.warm(request).await.unwrap_err();
        assert_eq!(error.to_string(), "Anthropic is not configured on this relay");
        assert_eq!(error.code(), "provider_not_configured");
    }
}
